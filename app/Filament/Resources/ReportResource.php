<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportResource\Pages;
use App\Models\Report;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?string $modelLabel = 'reporte';

    protected static ?string $pluralModelLabel = 'reportes';

    /**
     * Eager load para evitar N+1 en la tabla y en el detalle
     * (reporter.profile / reported.profile — se muestran ambos nombres
     * con fallback a email; reported.profile.photos alimenta la sección
     * "Fotos del perfil reportado" de ViewReport, mismo patrón que
     * VerificationRequestResource con profile.photos).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reporter.profile', 'reported.profile.photos']);
    }

    // Los Report no se crean ni editan a mano desde el panel — solo se
    // originan desde la app móvil (SafetyService::reportUser()).
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reporter.profile.display_name')
                    ->label('Reportante')
                    ->getStateUsing(fn (Report $record): string => $record->reporter->profile?->display_name
                        ?? $record->reporter->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('reporter', function (Builder $q) use ($search) {
                            $q->where('email', 'like', "%{$search}%")
                                ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('reported.profile.display_name')
                    ->label('Reportado')
                    ->getStateUsing(fn (Report $record): string => $record->reported->profile?->display_name
                        ?? $record->reported->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('reported', function (Builder $q) use ($search) {
                            $q->where('email', 'like', "%{$search}%")
                                ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Razón')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => static::reasonLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(40)
                    ->placeholder('—')
                    ->tooltip(fn (Report $record): ?string => $record->description),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'reviewed' => 'info',
                        'resolved' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::statusLabels()[$state] ?? $state)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $status = static::resolveStatusFromSearch($search);

                        // Mismo criterio que VerificationRequestResource: sin
                        // coincidencia conocida no se agrega ninguna condición
                        // (Filament ya envuelve este closure en su propio
                        // grupo where/orWhere junto con las demás columnas).
                        return $status
                            ? $query->where('status', $status)
                            : $query;
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Reportado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(static::statusLabels()),

                SelectFilter::make('reason')
                    ->label('Razón')
                    ->options(static::reasonLabels()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReports::route('/'),
            'view' => Pages\ViewReport::route('/{record}'),
        ];
    }

    /**
     * Labels en español ya aprobados en brand/copies.md → "Reportar"
     * (motivos del modal de reporte en la app móvil, reutilizados aquí
     * para mantener consistencia).
     */
    public static function reasonLabels(): array
    {
        return [
            'harassment' => 'Acoso',
            'discrimination' => 'Discriminación',
            'fake_profile' => 'Perfil falso',
            'inappropriate_content' => 'Contenido inapropiado',
            'other' => 'Otro',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            'pending' => 'Pendiente',
            'reviewed' => 'Revisado',
            'resolved' => 'Resuelto',
        ];
    }

    /**
     * Traduce lo que un admin escribiría en el buscador ("pendiente",
     * "revisado", "resuelto" — o el valor crudo en inglés) al valor real
     * guardado en `status`. Devuelve null si no matchea ningún estado
     * conocido. Distinto del helper equivalente en
     * VerificationRequestResource: el enum de Report.status
     * (pending/reviewed/resolved) no es el mismo que el de
     * VerificationRequest.status (pending/approved/rejected).
     */
    protected static function resolveStatusFromSearch(string $search): ?string
    {
        $normalized = Str::of($search)->trim()->lower()->ascii()->toString();

        return match ($normalized) {
            'pending', 'pendiente' => 'pending',
            'reviewed', 'revisado', 'revisada' => 'reviewed',
            'resolved', 'resuelto', 'resuelta' => 'resolved',
            default => null,
        };
    }
}
