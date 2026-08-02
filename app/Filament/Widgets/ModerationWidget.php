<?php

namespace App\Filament\Widgets;

use App\Models\Report;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → ModerationWidget.
 * "Resueltos últimos 7 días" usa updated_at como proxy de cuándo se resolvió
 * — Report no tiene resolved_at propio (a diferencia de VerificationRequest),
 * mismo criterio ya documentado en features/safety/specs/plan.md.
 */
class ModerationWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $pendingCount = Report::where('status', 'pending')->count();

        return [
            // Mismo lenguaje de color que ReportResource/UserResource:
            // 'warning' para pendiente, no 'danger' — se mantiene aquí
            // aunque haya pendientes, y pasa a 'success' en 0.
            Stat::make('Reportes pendientes', $pendingCount)
                ->icon('heroicon-o-flag')
                ->color($pendingCount > 0 ? 'warning' : 'success'),

            Stat::make('Resueltos últimos 7 días', Report::where('status', 'resolved')
                    ->where('updated_at', '>=', now()->subDays(7))
                    ->count())
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}
