<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            // Corrección 2026-07-15 documentada en plan.md: UUID PK propio +
            // UNIQUE(blocker_id, blocked_id), nunca una llave primaria
            // compuesta (constitution.md → "Database Rules: Primary keys:
            // UUID strings", sin excepción para esta tabla).
            $table->uuid('id')->primary();
            $table->foreignUuid('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocker_id');
            $table->index('blocked_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
