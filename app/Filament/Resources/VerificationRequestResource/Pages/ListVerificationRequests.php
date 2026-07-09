<?php

namespace App\Filament\Resources\VerificationRequestResource\Pages;

use App\Filament\Resources\VerificationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListVerificationRequests extends ListRecords
{
    protected static string $resource = VerificationRequestResource::class;

    // Sin acción de "crear": las VerificationRequest solo se originan desde
    // la app móvil (VerificationService::submit). El panel únicamente revisa.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
