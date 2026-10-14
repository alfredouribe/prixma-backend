<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeedbackResource\Pages;
use App\Models\Feedback;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Log inmutable de reportes de bug / comentarios libres enviados desde la
 * app (`POST /api/feedback`, ya construido — ver CLAUDE.md). Mismo criterio
 * que PackageGrantResource: solo lectura, sin create/update/delete, sin
 * página de detalle separada — la tabla ya muestra todo lo necesario
 * (mensaje completo vía tooltip, mismo patrón que ReportResource con su
 * columna `description`).
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Soporte';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    /**
     * Eager load para evitar N+1 en la tabla — user.profile para el nombre
     * con fallback a email, mismo patrón que ReportResource/PackageGrantResource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user.profile']);
    }

    // Log inmutable — nunca se crea/edita a mano desde el panel, solo se
    // origina desde FeedbackService::submit() (app móvil).
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.profile.display_name')
                    ->label('Usuario')
                    ->getStateUsing(fn (Feedback $record): string => $record->user->profile?->display_name
                        ?? $record->user->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('email', 'like', "%{$search}%")
                                ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'bug' => 'danger',
                        'comment' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::typeLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('message')
                    ->label('Mensaje')
                    ->limit(50)
                    ->tooltip(fn (Feedback $record): string => $record->message)
                    ->searchable(),

                Tables\Columns\TextColumn::make('app_version')
                    ->label('Versión')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('platform')
                    ->label('Plataforma')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        'ios' => 'iOS',
                        'android' => 'Android',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enviado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(static::typeLabels()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedback::route('/'),
        ];
    }

    /**
     * Labels en español — sin copy aprobado en brand/copies.md para este
     * tooling interno (panel admin, mismo criterio ya documentado en
     * ReportResource/AdminPanelProvider para texto staff-facing).
     */
    public static function typeLabels(): array
    {
        return [
            'bug' => 'Bug',
            'comment' => 'Comentario',
        ];
    }
}
