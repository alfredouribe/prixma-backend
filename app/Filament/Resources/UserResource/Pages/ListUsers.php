<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    // Sin acción de "crear": los usuarios finales solo se originan desde la
    // app móvil (registro). El panel únicamente revisa (solo lectura).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
