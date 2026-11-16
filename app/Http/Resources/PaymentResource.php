<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            // El client_secret solo sirve mientras el pago está abierto; después no se expone
            'client_secret' => $this->when(! in_array($this->status, ['succeeded', 'refunded', 'canceled', 'failed'], true), $this->stripe_client_secret),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'refunded_at' => $this->refunded_at,
            'created_at' => $this->created_at,
        ];
    }
}
