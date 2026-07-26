<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Los eventos se crean exclusivamente desde el panel admin
            // (Filament) por una cuenta de staff — ver domain.md → Event y
            // features/events/specs/spec.md → "Creación de eventos"
            // (2026-07-26). No referencia a `users`.
            $table->foreignUuid('creator_id')->constrained('admins')->cascadeOnDelete();

            $table->string('title', 100);
            $table->text('description')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->enum('category', ['pride', 'social', 'art', 'activism']);
            $table->dateTime('event_date');
            $table->string('location_name');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('external_link')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_key')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('creator_id');
            $table->index(['category', 'event_date']);
            $table->index('event_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
