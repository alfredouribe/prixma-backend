<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->enum('plan', ['free', 'prixma_plus'])->default('free');
            $table->enum('status', ['active', 'cancelled', 'expired']);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Nullable: un registro `free` recién creado (si alguna vez se
            // llega a sembrar uno) no tiene proveedor todavía. Solo Google
            // Play Billing en esta ronda (ver spec.md → "Decisiones
            // confirmadas") — apple/stripe quedan en el enum para cuando se
            // retomen, sin uso real por ahora.
            $table->enum('provider', ['apple', 'google', 'stripe'])->nullable();

            // ID de transacción/suscripción de RevenueCat.
            $table->string('provider_ref')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
