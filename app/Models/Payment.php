<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $stripe_response
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id', 'stripe_payment_intent_id', 'stripe_client_secret', 'stripe_charge_id', 'stripe_refund_id',
        'amount', 'currency', 'status', 'payment_method', 'stripe_response', 'paid_at', 'refunded_at',
    ];

    protected $hidden = ['stripe_response'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'stripe_response' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
