<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enum original no incluía estados reales de Stripe como "requires_action" (3-D Secure)
 * o "requires_capture"; guardarlos fallaba en MySQL. Se cambia a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 30)->default('requires_payment_method')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('status', ['requires_payment_method', 'requires_confirmation', 'processing', 'succeeded', 'canceled', 'failed'])
                ->default('requires_payment_method')->change();
        });
    }
};
