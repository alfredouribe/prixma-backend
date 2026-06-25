<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('swiper_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('swiped_id')->constrained('users')->cascadeOnDelete();
            $table->enum('direction', ['like', 'dislike', 'super_like']);
            $table->timestamp('created_at');

            $table->unique(['swiper_id', 'swiped_id']);
            $table->index(['swiped_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swipes');
    }
};
