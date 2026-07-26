<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_rsvps', function (Blueprint $table) {
            // UUID PK propio en vez de PRIMARY KEY (event_id, user_id)
            // compuesta — mismo patrón ya corregido antes en `blocks` (ver
            // features/events/specs/plan.md, constitution.md → "Database
            // Rules: Primary keys: UUID strings", sin excepción para esta
            // tabla).
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['interested', 'going', 'not_going']);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index('event_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
    }
};
