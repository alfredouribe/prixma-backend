<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            // utf8mb4/utf8mb4_unicode_ci explícito — los mensajes pueden
            // contener emojis (conventions/backend.md → "Charset y Collation").
            $table->text('content')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            // messages tiene soft delete por auditoría (conventions/backend.md
            // → "Soft Deletes — Estrategia"), aunque en este pase no existe
            // ningún endpoint que borre mensajes — domain.md: "los mensajes
            // no se eliminan físicamente" incluso al rechazar una solicitud.
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
