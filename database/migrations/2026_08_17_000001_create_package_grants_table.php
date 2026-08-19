<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log inmutable de cada otorgamiento de `Package` a un usuario — ver
     * features/premium/specs/plan.md → "Historial de paquetes otorgados".
     * Snapshot de `package_name`/`grant_type`/`grant_value` (no solo el FK):
     * si el `Package` original se edita o se borra después, el historial
     * sigue siendo legible y correcto tal como era en el momento real del
     * otorgamiento — mismo criterio ya usado en `reports.profile_snapshot`/
     * `chat_snapshot`.
     */
    public function up(): void
    {
        Schema::create('package_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Referencia viva mientras el paquete exista — se pone en null si
            // el paquete original se borra, el snapshot de abajo no depende
            // de esta columna para seguir siendo legible.
            $table->foreignUuid('package_id')->nullable()->constrained('packages')->nullOnDelete();

            // Quién de staff lo otorgó — null si se otorgó sin admin (ej.
            // llamadas de test que no pasan `$grantedBy`).
            $table->foreignUuid('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->string('package_name');
            $table->string('grant_type');
            $table->unsignedInteger('grant_value');

            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_grants');
    }
};
