<?php

namespace App\Services;

use App\Mail\OrderRefunded;
use App\Mail\PaymentReceived;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Encapsula toda la comunicación con la API de Stripe (stripe/stripe-php).
 */
class StripeService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        // Sin clave configurada se crea el cliente vacío (las llamadas fallarán con un error controlado)
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: []);
    }

    /**
     * Crea un PaymentIntent para una orden y registra el pago en la tabla payments.
     */
    public function createPaymentIntent(Order $order): Payment
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => (int) round($order->total * 100), // Stripe trabaja en centavos
            'currency' => $order->currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
            ],
        ]);

        return Payment::create([
            'order_id' => $order->id,
            'stripe_payment_intent_id' => $intent->id,
            'stripe_client_secret' => $intent->client_secret,
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => $intent->status,
            'stripe_response' => $intent->toArray(),
        ]);
    }

    /**
     * Consulta el estado actual del PaymentIntent en Stripe y sincroniza la orden.
     */
    public function syncPaymentStatus(Payment $payment): Payment
    {
        $intent = $this->stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);

        return $this->applyIntent($payment, $intent);
    }

    /**
     * Confirma un PaymentIntent desde el backend usando un payment method de prueba
     * (útil para probar el flujo completo desde Swagger sin frontend).
     */
    public function confirmPaymentIntent(Payment $payment, string $paymentMethod = 'pm_card_visa'): Payment
    {
        $intent = $this->stripe->paymentIntents->confirm($payment->stripe_payment_intent_id, [
            'payment_method' => $paymentMethod,
            'return_url' => config('app.url').'/api/payments/return',
        ]);

        return $this->applyIntent($payment, $intent);
    }

    /**
     * Cancela un PaymentIntent que todavía no se ha cobrado (por ejemplo, al expirar la orden).
     */
    public function cancelPaymentIntent(Payment $payment): void
    {
        $this->stripe->paymentIntents->cancel($payment->stripe_payment_intent_id);
        $payment->update(['status' => 'canceled']);
    }

    /**
     * Aplica el estado de un PaymentIntent al pago y a la orden.
     *
     * Seguridad: una orden solo pasa a "paid" si el monto, la moneda y la orden del intent
     * coinciden con lo registrado, y si la orden sigue pendiente (no se marcan como pagadas
     * órdenes canceladas cuyo stock ya se liberó).
     */
    public function applyIntent(Payment $payment, PaymentIntent $intent): Payment
    {
        $payment->status = $intent->status;
        $payment->payment_method = is_string($intent->payment_method) ? $intent->payment_method : ($intent->payment_method->id ?? null);
        $payment->stripe_charge_id = $intent->latest_charge ?? null;
        $payment->stripe_response = $intent->toArray();

        $order = $payment->order;

        if ($intent->status === 'succeeded') {
            if (! $this->intentMatchesOrder($intent, $order)) {
                Log::critical('Pago de Stripe no coincide con la orden; no se marca como pagada.', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'intent_amount' => $intent->amount,
                    'intent_currency' => $intent->currency,
                ]);
            } elseif ($order->status !== Order::STATUS_PENDING) {
                // Ej.: la orden expiró y su stock se liberó antes de que llegara el pago
                Log::warning('Pago recibido para una orden que ya no está pendiente: requiere revisión o reembolso.', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                ]);
            } else {
                $payment->paid_at = now();
                $order->update(['status' => Order::STATUS_PAID]);
                Mail::to($order->user)->send(new PaymentReceived($order));
            }
        } elseif ($intent->status === 'canceled') {
            $order->markAsCancelled(); // repone el stock reservado
        }

        $payment->save();

        return $payment;
    }

    /**
     * Reembolsa el pago completo en Stripe y cierra la orden (repone stock y cupón).
     */
    public function refundPayment(Payment $payment): Payment
    {
        $refund = $this->stripe->refunds->create([
            'payment_intent' => $payment->stripe_payment_intent_id,
            'metadata' => ['order_id' => $payment->order_id],
        ]);

        return $this->markRefunded($payment, $refund->id);
    }

    /**
     * Registra un reembolso (hecho desde la API o desde el dashboard de Stripe vía webhook).
     * Es idempotente: si la orden ya estaba reembolsada no repone el stock otra vez.
     */
    public function markRefunded(Payment $payment, ?string $refundId = null): Payment
    {
        $payment->update([
            'status' => 'refunded',
            'stripe_refund_id' => $refundId ?? $payment->stripe_refund_id,
            'refunded_at' => $payment->refunded_at ?? now(),
        ]);

        if ($payment->order->markAsRefunded()) {
            Mail::to($payment->order->user)->send(new OrderRefunded($payment->order));
        }

        return $payment;
    }

    /** Verifica que el intent corresponda a la orden: monto en centavos, moneda y metadata. */
    protected function intentMatchesOrder(PaymentIntent $intent, Order $order): bool
    {
        $expectedAmount = (int) round(((float) $order->total) * 100);
        $metadata = $intent->metadata ?? null;
        $metadataOrderId = $metadata ? ($metadata['order_id'] ?? null) : null;

        return (int) $intent->amount === $expectedAmount
            && strtolower((string) $intent->currency) === strtolower($order->currency)
            && (string) $metadataOrderId === (string) $order->id;
    }

    /**
     * Verifica la firma del webhook. Si no hay secreto configurado se rechaza siempre:
     * con un secreto vacío cualquiera podría firmar eventos falsos.
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '' || $signature === '') {
            throw new SignatureVerificationException('Webhook de Stripe no configurado o sin firma.');
        }

        return \Stripe\Webhook::constructEvent($payload, $signature, $secret);
    }
}
