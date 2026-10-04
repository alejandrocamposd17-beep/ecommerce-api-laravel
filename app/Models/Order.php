<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['user_id', 'order_number', 'status', 'subtotal', 'tax', 'total', 'currency', 'shipping_address', 'notes'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    /** Marca la orden como rechazada y devuelve al inventario lo que se había reservado. */
    public function markAsFailed(): bool
    {
        return $this->closeAndReleaseStock(self::STATUS_FAILED);
    }

    /** Marca la orden como cancelada y devuelve al inventario lo que se había reservado. */
    public function markAsCancelled(): bool
    {
        return $this->closeAndReleaseStock(self::STATUS_CANCELLED);
    }

    /**
     * Cierra una orden pendiente y repone el stock de sus productos.
     *
     * Es idempotente: solo actúa si la orden sigue en "pending". Así el stock no se repone
     * dos veces cuando el rechazo llega primero por /payments/{id}/confirm y después por el
     * webhook de Stripe, y nunca se repone el stock de una orden ya pagada.
     * Devuelve true si se repuso el stock.
     */
    protected function closeAndReleaseStock(string $status): bool
    {
        $released = DB::transaction(function () use ($status) {
            /** @var self|null $order */
            $order = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $order || $order->status !== self::STATUS_PENDING) {
                return false;
            }

            foreach ($order->items()->get() as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
            }

            $order->update(['status' => $status]);

            return true;
        });

        $this->refresh();

        return $released;
    }

    public static function generateOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -6));
    }
}
