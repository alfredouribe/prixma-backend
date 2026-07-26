<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VerificationRequestResource\Pages;
use App\Models\VerificationRequest;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class VerificationRequestResource extends Resource
{
    protected static ?string $model = VerificationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Verificaciones';

    protected static ?string $modelLabel = 'solicitud de verificación';

    protected static ?string $pluralModelLabel = 'solicitudes de verificación';

    /**
     * Eager load para evitar N+1 en la tabla (profile.display_name,
     * profile.user.email) y en el detalle (profile.photos).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile.user', 'profile.photos']);
    }

    // Las VerificationRequest no se crean ni editan a mano desde el panel.
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('profile.display_name')
                    ->label('Usuario')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('profile.user.email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        default => $state,
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $status = static::resolveStatusFromSearch($search);

                        // Filament ya envuelve este closure en su propio grupo
                        // where/orWhere (ver InteractsWithTableQuery::applySearchConstraint),
                        // así que aquí basta con `where`. Sin coincidencia conocida:
                        // no se agrega ninguna condición (no aporta filas por esta
                        // vía) pero tampoco rompe el OR combinado del resto de columnas.
                        return $status
                            ? $query->where('status', $status)
                            : $query;
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Solicitado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyCreatedAtSearch($query, $search);
                    }),

                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Revisado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                    ]),

                Filter::make('created_at')
                    ->label('Rango de fecha')
                    ->form([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
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
            'index' => Pages\ListVerificationRequests::route('/'),
            'view' => Pages\ViewVerificationRequest::route('/{record}'),
        ];
    }

    /**
     * Traduce lo que un admin escribiría en el buscador ("pendiente",
     * "aprobado", "rechazado" — o el valor crudo en inglés) al valor real
     * guardado en `status`. Devuelve null si no matchea ningún estado
     * conocido, en cuyo caso el caller no debe agregar ninguna condición.
     */
    protected static function resolveStatusFromSearch(string $search): ?string
    {
        $normalized = Str::of($search)->trim()->lower()->ascii()->toString();

        return match ($normalized) {
            'pending', 'pendiente' => 'pending',
            'approved', 'aprobado', 'aprobada' => 'approved',
            'rejected', 'rechazado', 'rechazada' => 'rejected',
            default => null,
        };
    }

    /**
     * Intenta interpretar el término de búsqueda como una fecha (completa
     * en formato d/m/Y, o parcial: mes/año o solo año) y filtra
     * `created_at` en consecuencia. Si el texto no parsea como una fecha
     * razonable, no agrega ninguna condición — nunca lanza excepción.
     */
    protected static function applyCreatedAtSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        // Fecha completa: d/m/Y (acepta día/mes de 1 o 2 dígitos)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $search, $matches)) {
            [$day, $month, $year] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];

            if (checkdate($month, $day, $year)) {
                return $query->whereDate('created_at', sprintf('%04d-%02d-%02d', $year, $month, $day));
            }

            return $query;
        }

        // Parcial: mes/año
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $search, $matches)) {
            $month = (int) $matches[1];
            $year = (int) $matches[2];

            if ($month >= 1 && $month <= 12 && $year >= 1) {
                return $query->whereYear('created_at', $year)->whereMonth('created_at', $month);
            }

            return $query;
        }

        // Parcial: solo año (4 dígitos)
        if (preg_match('/^\d{4}$/', $search)) {
            return $query->whereYear('created_at', (int) $search);
        }

        return $query;
    }
}
