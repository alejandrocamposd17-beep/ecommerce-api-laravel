<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evento de Stripe ya procesado (idempotencia del webhook). */
class StripeEvent extends Model
{
    protected $fillable = ['event_id', 'type', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
