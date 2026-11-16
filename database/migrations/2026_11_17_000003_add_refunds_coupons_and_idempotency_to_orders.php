<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - status de órdenes pasa de enum a string para admitir "refunded".
 * - Descuento y cupón aplicado.
 * - Idempotency-Key: evita órdenes duplicadas cuando el cliente reintenta la petición.
 * - Datos del reembolso en payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('subtotal');
            $table->foreignId('coupon_id')->nullable()->after('discount')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->after('order_number');
            $table->string('idempotency_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('stripe_refund_id')->nullable()->after('stripe_charge_id');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['stripe_refund_id', 'refunded_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['discount', 'idempotency_key', 'idempotency_hash']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled'])->default('pending')->change();
        });
    }
};
