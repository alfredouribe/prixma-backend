<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El documento de identidad ya no se sube a S3 (ver constitution.md ->
     * "Media Upload Pipeline", excepcion documentada para Verification).
     * Se guarda en el disco `local` privado del backend, asi que las
     * columnas dejan de ser "keys de S3" y pasan a ser rutas de archivo.
     */
    public function up(): void
    {
        Schema::table('verification_requests', function (Blueprint $table) {
            $table->renameColumn('document_s3_key', 'document_path');
            $table->renameColumn('selfie_s3_key', 'selfie_path');
        });
    }

    public function down(): void
    {
        Schema::table('verification_requests', function (Blueprint $table) {
            $table->renameColumn('document_path', 'document_s3_key');
            $table->renameColumn('selfie_path', 'selfie_s3_key');
        });
    }
};
