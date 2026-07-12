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
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('notify_matches_enabled')->default(true)->after('reports_enabled');
            $table->boolean('notify_messages_enabled')->default(true)->after('notify_matches_enabled');
            $table->boolean('notify_events_enabled')->default(true)->after('notify_messages_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notify_matches_enabled',
                'notify_messages_enabled',
                'notify_events_enabled',
            ]);
        });
    }
};
