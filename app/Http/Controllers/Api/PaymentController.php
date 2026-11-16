<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

class PaymentController extends ApiController
{
    public function __construct(protected StripeService $stripe) {}

    #[OA\Post(
        path: '/api/v1/orders/{id}/pay',
        tags: ['Pagos'],
        summary: 'Iniciar el pago de una orden (crea un PaymentIntent en Stripe)',
        description: 'Devuelve el client_secret que el frontend usa con Stripe.js / Elements para completar el pago.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'PaymentIntent creado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Payment'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Orden no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'La orden ya fue pagada o cancelada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 502, description: 'Error de comunicación con Stripe', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function pay(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->error('El recurso solicitado no existe.', 404);
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return $this->error("La orden se encuentra en estado '{$order->status}' y no puede pagarse.", 409);
        }

        // Reutiliza un intento pendiente si ya existe
        $existing = $order->payment()->whereNotIn('status', ['succeeded', 'canceled'])->first();
        if ($existing) {
            return $this->success(new PaymentResource($existing), 'Ya existe un intento de pago pendiente para esta orden.');
        }

        try {
            $payment = $this->stripe->createPaymentIntent($order);
        } catch (ApiErrorException $e) {
            return $this->stripeUnavailable($e, 'pay', $order->id);
        }

        return $this->success(new PaymentResource($payment), 'Intento de pago creado. Use el client_secret para completar el pago.', 201);
    }

    #[OA\Post(
        path: '/api/v1/payments/{id}/confirm',
        tags: ['Pagos'],
        summary: 'Confirmar el pago desde el backend (modo prueba)',
        description: 'Confirma el PaymentIntent con un método de pago de prueba de Stripe (por defecto pm_card_visa). Permite probar el flujo completo desde Swagger sin frontend.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID del pago', schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'payment_method', type: 'string', example: 'pm_card_visa', description: 'Test payment method de Stripe (pm_card_visa, pm_card_mastercard, pm_card_chargeDeclined, etc.)'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Pago confirmado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Payment'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 402, description: 'Pago rechazado por Stripe', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Pago no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function confirm(ConfirmPaymentRequest $request, Payment $payment): JsonResponse
    {
        if ($payment->order->user_id !== $request->user()->id) {
            return $this->error('El recurso solicitado no existe.', 404);
        }

        if ($payment->status === 'succeeded') {
            return $this->success(new PaymentResource($payment), 'Este pago ya fue procesado.');
        }

        // Confirmar desde el backend con tarjetas de prueba es solo para desarrollo/QA:
        // en producción el cliente confirma con Stripe.js usando el client_secret.
        if (app()->isProduction() && ! config('services.stripe.allow_server_confirm')) {
            return $this->error('La confirmación desde el servidor solo está disponible en modo de prueba. Use Stripe.js con el client_secret.', 403);
        }

        // No se puede cobrar una orden que ya expiró, falló o se canceló (su stock ya se liberó)
        if ($payment->order->status !== Order::STATUS_PENDING) {
            return $this->error("La orden se encuentra en estado '{$payment->order->status}' y no puede pagarse.", 409);
        }

        try {
            $payment = $this->stripe->confirmPaymentIntent($payment, $request->validated('payment_method') ?? 'pm_card_visa');
        } catch (\Stripe\Exception\CardException $e) {
            $payment->update(['status' => 'failed', 'stripe_response' => $e->getJsonBody()]);
            // La orden pasa a "failed" y el stock reservado vuelve al inventario
            $payment->order->markAsFailed();

            return $this->error('Pago rechazado: '.$e->getMessage(), 402);
        } catch (ApiErrorException $e) {
            return $this->stripeUnavailable($e, 'confirm', $payment->order_id);
        }

        return $this->success(new PaymentResource($payment), $payment->status === 'succeeded' ? 'Pago procesado correctamente.' : "Estado del pago: {$payment->status}.");
    }

    #[OA\Get(
        path: '/api/v1/payments/{id}',
        tags: ['Pagos'],
        summary: 'Consultar estado de un pago (sincroniza con Stripe)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Estado del pago', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Payment'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Pago no encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->order->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return $this->error('El recurso solicitado no existe.', 404);
        }

        try {
            $payment = $this->stripe->syncPaymentStatus($payment);
        } catch (ApiErrorException $e) {
            // Si Stripe no responde, se devuelve el último estado conocido.
        }

        return $this->success(new PaymentResource($payment), 'Estado del pago.');
    }

    #[OA\Post(
        path: '/api/v1/stripe/webhook',
        tags: ['Pagos'],
        summary: 'Webhook de Stripe (pagos y reembolsos)',
        description: 'Endpoint público que Stripe invoca. Verifica la firma con STRIPE_WEBHOOK_SECRET, ignora eventos repetidos (idempotente por ID de evento) y actualiza el pago y la orden. Eventos: payment_intent.succeeded, payment_intent.payment_failed, payment_intent.canceled y charge.refunded.',
        responses: [
            new OA\Response(response: 200, description: 'Evento procesado'),
            new OA\Response(response: 400, description: 'Firma inválida', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->constructWebhookEvent($request->getContent(), $request->header('Stripe-Signature', ''));
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return $this->error('Firma de webhook inválida.', 400);
        }

        // Stripe puede reenviar el mismo evento: cada ID se procesa una sola vez
        if (StripeEvent::where('event_id', $event->id)->exists()) {
            return $this->success(['received' => true, 'type' => $event->type, 'duplicate' => true], 'Evento ya procesado.');
        }

        try {
            DB::transaction(function () use ($event) {
                // Se registra primero: si dos entregas llegan a la vez, el índice único detiene la segunda
                StripeEvent::create(['event_id' => $event->id, 'type' => $event->type, 'processed_at' => now()]);
                $this->handleEvent($event);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->success(['received' => true, 'type' => $event->type, 'duplicate' => true], 'Evento ya procesado.');
        }

        return $this->success(['received' => true, 'type' => $event->type], 'Evento procesado.');
    }

    protected function handleEvent(\Stripe\Event $event): void
    {
        if (in_array($event->type, ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled'])) {
            /** @var \Stripe\PaymentIntent $intent */
            $intent = $event->data->object;
            $payment = Payment::where('stripe_payment_intent_id', $intent->id)->first();

            if ($payment) {
                $this->stripe->applyIntent($payment, $intent);
                if ($event->type === 'payment_intent.payment_failed') {
                    $payment->update(['status' => 'failed']);
                    // Idempotente: si /confirm ya repuso el stock, aquí no se repone otra vez
                    $payment->order->markAsFailed();
                }
            }

            return;
        }

        // Reembolso completo hecho desde el dashboard de Stripe
        if ($event->type === 'charge.refunded') {
            /** @var \Stripe\Charge $charge */
            $charge = $event->data->object;
            $payment = Payment::where('stripe_payment_intent_id', $charge->payment_intent)->first();

            if ($payment && $charge->refunded) {
                $this->stripe->markRefunded($payment);
            }
        }
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/refund',
        tags: ['Administración'],
        summary: 'Reembolsar una orden pagada (requiere rol admin)',
        description: 'Reembolsa el pago completo en Stripe, marca la orden como refunded, devuelve el stock, libera el cupón y avisa al cliente por correo.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Reembolso procesado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Payment'),
            ])),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'La orden no está pagada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 502, description: 'Error de comunicación con Stripe', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function refund(Request $request, Order $order): JsonResponse
    {
        $payment = $order->payments()->where('status', 'succeeded')->latest('id')->first();

        if ($order->status !== Order::STATUS_PAID || ! $payment) {
            return $this->error("La orden se encuentra en estado '{$order->status}' y no puede reembolsarse.", 409);
        }

        try {
            $payment = $this->stripe->refundPayment($payment);
        } catch (ApiErrorException $e) {
            return $this->stripeUnavailable($e, 'refund', $order->id);
        }

        AuditLog::record($request, 'order.refunded', $order, [
            'amount' => (float) $payment->amount,
            'stripe_refund_id' => $payment->stripe_refund_id,
        ]);

        return $this->success(new PaymentResource($payment), 'Reembolso procesado correctamente.');
    }

    /**
     * Error genérico hacia el cliente: el detalle de Stripe se registra en el log, no se expone.
     */
    protected function stripeUnavailable(ApiErrorException $e, string $action, int|string|null $orderId): JsonResponse
    {
        Log::error('Error de Stripe', ['action' => $action, 'order_id' => $orderId, 'message' => $e->getMessage()]);

        return $this->error('No se pudo procesar el pago con Stripe. Intente de nuevo en unos minutos.', 502);
    }
}
