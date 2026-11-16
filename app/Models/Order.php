<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id', 'order_number', 'idempotency_key', 'idempotency_hash', 'status', 'subtotal', 'discount',
        'coupon_id', 'tax', 'total', 'currency', 'shipping_address', 'notes',
    ];

    protected $hidden = ['idempotency_hash'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasOne<Payment, $this> */
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

    /** Marca una orden pagada como reembolsada y devuelve los productos al inventario. */
    public function markAsRefunded(): bool
    {
        return $this->closeAndReleaseStock(self::STATUS_REFUNDED, self::STATUS_PAID);
    }

    /**
     * Cierra una orden pendiente y repone el stock de sus productos (y el uso del cupón).
     *
     * Es idempotente: solo actúa si la orden sigue en el estado esperado. Así el stock no se repone
     * dos veces cuando el rechazo llega primero por /payments/{id}/confirm y después por el
     * webhook de Stripe, y nunca se repone el stock de una orden ya pagada.
     * Devuelve true si se repuso el stock.
     */
    protected function closeAndReleaseStock(string $status, string $from = self::STATUS_PENDING): bool
    {
        $released = DB::transaction(function () use ($status, $from) {
            /** @var self|null $order */
            $order = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $order || $order->status !== $from) {
                return false;
            }

            foreach ($order->items()->get() as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
            }

            // El cupón vuelve a quedar disponible: la orden que lo usó no se concretó
            if ($order->coupon_id) {
                Coupon::whereKey($order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }

            $order->update(['status' => $status]);

            return true;
        });

        $this->refresh();

        return $released;
    }

    public static function generateOrderNumber(): string
    {
        // 8 caracteres aleatorios (criptográficamente seguros): no predecibles ni secuenciales
        return 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
    }
}
