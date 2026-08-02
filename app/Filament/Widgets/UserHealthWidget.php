<?php

namespace App\Filament\Widgets;

use App\Models\Profile;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → UserHealthWidget.
 */
class UserHealthWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $verifiedCount = Profile::where('verification_status', 'verified')->count();
        $totalProfiles = Profile::count();

        return [
            Stat::make('Verificados', $verifiedCount)
                ->description("{$verifiedCount} de {$totalProfiles}")
                ->icon('heroicon-o-shield-check')
                ->color('success'),

            Stat::make('Premium activos', User::where('is_premium', true)->count())
                ->icon('heroicon-o-star')
                ->color('warning'),

            Stat::make('Suspendidos/baneados', User::whereIn('status', ['suspended', 'banned'])->count())
                ->icon('heroicon-o-no-symbol')
                ->color('danger'),
        ];
    }
}
