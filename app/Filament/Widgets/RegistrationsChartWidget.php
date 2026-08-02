<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → RegistrationsChartWidget.
 * Primer ChartWidget del proyecto — no había precedente.
 */
class RegistrationsChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Registros — últimos 30 días';

    protected function getData(): array
    {
        // Últimos 30 días calendario, incluyendo hoy.
        $days = collect(range(29, 0))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString())
            ->values();

        $counts = User::query()
            ->whereDate('created_at', '>=', now()->subDays(29)->startOfDay())
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'date');

        return [
            'datasets' => [
                [
                    'label' => 'Registros',
                    'data' => $days->map(fn (string $date) => (int) ($counts[$date] ?? 0))->all(),
                ],
            ],
            'labels' => $days->map(fn (string $date) => Carbon::parse($date)->format('d/m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
