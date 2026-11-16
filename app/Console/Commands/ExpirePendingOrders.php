<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cancela las órdenes que siguen "pending" después del tiempo límite y devuelve su stock.
 * Sin esto, cualquiera podría reservar inventario indefinidamente sin pagar.
 */
class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending {--minutes= : Minutos de espera (por defecto config shop.pending_order_minutes)}';

    protected $description = 'Cancela órdenes pendientes vencidas, cancela su intento de pago en Stripe y libera el stock';

    public function handle(StripeService $stripe): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('shop.pending_order_minutes', 30));
        $cutoff = now()->subMinutes(max(1, $minutes));
        $expired = 0;

        Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->with('payment')
            ->chunkById(100, function ($orders) use ($stripe, &$expired) {
                foreach ($orders as $order) {
                    $payment = $order->payment;
                    if ($payment && ! in_array($payment->status, ['succeeded', 'canceled', 'failed'], true)) {
                        try {
                            $stripe->cancelPaymentIntent($payment);
                        } catch (Throwable $e) {
                            // Si Stripe no responde se intenta de nuevo en la próxima ejecución
                            $this->warn("No se pudo cancelar el pago de la orden {$order->order_number}: {$e->getMessage()}");

                            continue;
                        }
                    }

                    if ($order->markAsCancelled()) {
                        $expired++;
                    }
                }
            });

        $this->info("Órdenes expiradas: {$expired}");

        return self::SUCCESS;
    }
}
