<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log inmutable de reportes de bug / comentarios libres enviados desde
     * la app — ver CLAUDE.md (pedido nuevo, sin spec previa). Cada envío
     * dispara además un correo best-effort (`NewFeedbackMail`) y queda
     * visible en el panel admin (Filament, construido por otro agente a
     * partir de este modelo). Sin `updated_at` — mismo patrón ya usado en
     * este proyecto para `Swipe`/`UserMatch`/`PackageGrant`.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['bug', 'comment'])->default('comment');
            $table->text('message');

            // Informativo, lo manda el cliente — ej. "1.0.0".
            $table->string('app_version', 20)->nullable();

            // Mismo criterio que `device_tokens.platform` ya existente.
            $table->enum('platform', ['ios', 'android'])->nullable();

            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
