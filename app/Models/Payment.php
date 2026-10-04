<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id', 'stripe_payment_intent_id', 'stripe_client_secret', 'stripe_charge_id',
        'amount', 'currency', 'status', 'payment_method', 'stripe_response', 'paid_at',
    ];

    protected $hidden = ['stripe_response'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'stripe_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
