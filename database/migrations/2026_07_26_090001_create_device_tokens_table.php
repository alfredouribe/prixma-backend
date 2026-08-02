<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token');
            $table->enum('platform', ['ios', 'android']);
            $table->timestamps();

            $table->unique(['user_id', 'token'], 'device_tokens_user_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
