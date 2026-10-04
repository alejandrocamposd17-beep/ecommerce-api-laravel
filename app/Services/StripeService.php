<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
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
        $this->stripe = new StripeClient(config('services.stripe.secret'));
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

    public function applyIntent(Payment $payment, PaymentIntent $intent): Payment
    {
        $payment->status = $intent->status;
        $payment->payment_method = is_string($intent->payment_method) ? $intent->payment_method : ($intent->payment_method->id ?? null);
        $payment->stripe_charge_id = $intent->latest_charge ?? null;
        $payment->stripe_response = $intent->toArray();

        if ($intent->status === 'succeeded') {
            $payment->paid_at = now();
            $payment->order->update(['status' => Order::STATUS_PAID]);
        } elseif (in_array($intent->status, ['canceled'])) {
            $payment->order->markAsCancelled(); // repone el stock reservado
        }

        $payment->save();

        return $payment;
    }

    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $signature, config('services.stripe.webhook_secret'));
    }
}
