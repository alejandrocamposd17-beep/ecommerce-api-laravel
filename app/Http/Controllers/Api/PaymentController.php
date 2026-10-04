<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

class PaymentController extends ApiController
{
    public function __construct(protected StripeService $stripe) {}

    #[OA\Post(
        path: '/api/orders/{id}/pay',
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
            return $this->error('Error al comunicarse con Stripe: '.$e->getMessage(), 502);
        }

        return $this->success(new PaymentResource($payment), 'Intento de pago creado. Use el client_secret para completar el pago.', 201);
    }

    #[OA\Post(
        path: '/api/payments/{id}/confirm',
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

        try {
            $payment = $this->stripe->confirmPaymentIntent($payment, $request->validated('payment_method') ?? 'pm_card_visa');
        } catch (\Stripe\Exception\CardException $e) {
            $payment->update(['status' => 'failed', 'stripe_response' => $e->getJsonBody()]);
            // La orden pasa a "failed" y el stock reservado vuelve al inventario
            $payment->order->markAsFailed();

            return $this->error('Pago rechazado: '.$e->getMessage(), 402);
        } catch (ApiErrorException $e) {
            return $this->error('Error al comunicarse con Stripe: '.$e->getMessage(), 502);
        }

        return $this->success(new PaymentResource($payment), $payment->status === 'succeeded' ? 'Pago procesado correctamente.' : "Estado del pago: {$payment->status}.");
    }

    #[OA\Get(
        path: '/api/payments/{id}',
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
        path: '/api/stripe/webhook',
        tags: ['Pagos'],
        summary: 'Webhook de Stripe (payment_intent.succeeded / payment_failed)',
        description: 'Endpoint público que Stripe invoca. Verifica la firma con STRIPE_WEBHOOK_SECRET y actualiza el estado del pago y la orden.',
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
        }

        return $this->success(['received' => true, 'type' => $event->type], 'Evento procesado.');
    }
}
