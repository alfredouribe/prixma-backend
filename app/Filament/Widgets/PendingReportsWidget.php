<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReportResource;
use App\Models\Report;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → PendingReportsWidget.
 * Los últimos 5 reportes pendientes, con acceso directo a ReportResource —
 * no duplica la acción de cambiar status, que ya vive en ViewReport.
 */
class PendingReportsWidget extends BaseWidget
{
    protected static ?string $heading = 'Reportes pendientes recientes';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Report::query()
                    ->where('status', 'pending')
                    ->with(['reporter.profile', 'reported.profile'])
                    ->orderByDesc('created_at')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('reporter.profile.display_name')
                    ->label('Reportante')
                    ->getStateUsing(fn (Report $record): string => $record->reporter->profile?->display_name
                        ?? $record->reporter->email),

                Tables\Columns\TextColumn::make('reported.profile.display_name')
                    ->label('Reportado')
                    ->getStateUsing(fn (Report $record): string => $record->reported->profile?->display_name
                        ?? $record->reported->email),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Razón')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ReportResource::reasonLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Ver')
                    ->url(fn (Report $record): string => ReportResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
