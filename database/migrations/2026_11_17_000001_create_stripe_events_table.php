<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de eventos de Stripe ya procesados: Stripe puede reenviar el mismo evento
 * varias veces, así que el ID único evita aplicarlo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type', 100);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
