<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = ['code', 'type', 'value', 'min_subtotal', 'max_uses', 'used_count', 'expires_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Los códigos se guardan en mayúsculas para que la búsqueda no dependa de cómo los escriba el cliente. */
    protected function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Devuelve el motivo por el que el cupón no se puede usar, o null si es válido. */
    public function unavailableReason(float $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'El cupón no está activo.';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'El cupón ya venció.';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'El cupón alcanzó su límite de usos.';
        }
        if ($this->min_subtotal !== null && $subtotal < (float) $this->min_subtotal) {
            return 'El subtotal mínimo para este cupón es $'.number_format((float) $this->min_subtotal, 2).'.';
        }

        return null;
    }

    /** Descuento aplicable al subtotal (nunca mayor que el subtotal). */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min($discount, $subtotal), 2);
    }
}
