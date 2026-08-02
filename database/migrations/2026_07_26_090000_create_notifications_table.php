<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // `event_rsvp` (ya declarado en domain.md → Notification) queda
            // fuera de este enum a propósito — sin journey definido todavía,
            // ver features/notifications/specs/plan.md → "Nota — event_rsvp
            // fuera de alcance (2026-07-26)".
            $table->enum('type', ['match', 'message', 'super_like', 'request_accepted']);

            // utf8mb4 explícito — title/body pueden llevar emoji (copy fijo
            // como "¡Es un match! 🌟" o preview de un mensaje real), mismo
            // criterio que conventions/backend.md → "Charset y Collation"
            // aplicado en messages/events.
            $table->string('title')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('body')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');

            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
