<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * `creator_id` referencia `admins` (no `users`, ver domain.md → Event) —
     * siempre la cuenta de staff autenticada en el panel, nunca un campo del
     * formulario (spec.md: "solo cuentas admin pueden crear eventos, desde
     * el panel").
     *
     * Corrección 2026-07-26: `image_url` YA NO se deriva aquí con
     * `Storage::disk('s3')->url($key)` — el bucket real (`prixma`) es
     * privado (ACLs deshabilitados, "Bucket owner enforced"), así que una
     * URL plana sin firmar da 403 al intentar verla. `image_key` es la
     * única fuente de verdad persistida; `EventResource` (API móvil) genera
     * la URL firmada (`temporaryUrl()`) en cada respuesta, mismo patrón ya
     * usado para el video de perfil (`ProfileResource`/
     * `PublicProfileResource`).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creator_id'] = auth('admin')->id();

        return $data;
    }
}
