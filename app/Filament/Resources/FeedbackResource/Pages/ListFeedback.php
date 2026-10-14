<?php

namespace App\Filament\Resources\FeedbackResource\Pages;

use App\Filament\Resources\FeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    // Sin acción de "crear": el Feedback solo se origina desde la app móvil
    // (FeedbackService::submit()). El panel únicamente reporta.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
