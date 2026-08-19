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
        Schema::table('users', function (Blueprint $table) {
            // Saldo consumible, no caduca por tiempo — otorgado vía Package
            // (grant_type: super_like_credits). Ver
            // features/premium/specs/plan.md → "Super likes extra".
            $table->unsignedInteger('extra_super_likes')->default(0)->after('rewind_credits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('extra_super_likes');
        });
    }
};
