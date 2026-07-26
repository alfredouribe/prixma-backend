<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    // Sin acción de "crear": los Report solo se originan desde la app
    // móvil (SafetyService::reportUser). El panel únicamente revisa.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
