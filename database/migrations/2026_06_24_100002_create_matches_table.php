<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id_1')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('user_id_2')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['user_id_1', 'user_id_2']);
            $table->index('user_id_1');
            $table->index('user_id_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
