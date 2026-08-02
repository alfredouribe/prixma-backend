<?php

namespace App\Filament\Widgets;

use App\Models\Message;
use App\Models\Swipe;
use App\Models\UserMatch;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ver features/admin-dashboard/specs/plan.md → "Widgets" → EngagementWidget.
 * Mensajes hoy ya excluye soft-deletes por el scope global de Eloquent —
 * no hace falta withTrashed()/filtro manual.
 */
class EngagementWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Swipes hoy', Swipe::whereDate('created_at', today())->count())
                ->icon('heroicon-o-hand-raised')
                ->color('info'),

            Stat::make('Matches hoy', UserMatch::whereDate('created_at', today())->count())
                ->icon('heroicon-o-heart')
                ->color('success'),

            Stat::make('Mensajes hoy', Message::whereDate('created_at', today())->count())
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary'),
        ];
    }
}
