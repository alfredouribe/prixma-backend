<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageGrantResource\Pages;
use App\Models\PackageGrant;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte general de solo lectura — "qué se ha estado otorgando" sin entrar
 * usuario por usuario. Mismo patrón que ReportResource: sin create/update/
 * delete (es un log inmutable, nunca se edita a mano), tabla server-side.
 * Ver features/premium/specs/spec.md/plan.md → "Historial de paquetes
 * otorgados".
 *
 * A diferencia de ReportResource, no hay ninguna acción de estado que tomar
 * sobre una fila (un PackageGrant no se "revisa" ni transiciona), así que no
 * tiene página de detalle — mismo criterio que PackageResource, que tampoco
 * tiene página View propia.
 */
class PackageGrantResource extends Resource
{
    protected static ?string $model = PackageGrant::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Historial de paquetes';

    protected static ?string $modelLabel = 'otorgamiento';

    protected static ?string $pluralModelLabel = 'otorgamientos';

    /**
     * Eager load para evitar N+1 en la tabla — user.profile para el nombre
     * con fallback a email (mismo patrón que UserResource/ReportResource),
     * admin para "otorgado por". `package` (la relación viva, nullable) no
     * se carga porque la tabla nunca la usa — todo lo mostrado viene del
     * snapshot (package_name/grant_type/grant_value).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user.profile', 'admin']);
    }

    // Log inmutable — nunca se crea/edita a mano desde el panel, solo se
    // origina desde PackageService::grantToUser().
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
                    ->getStateUsing(fn (PackageGrant $record): string => $record->user->profile?->display_name
                        ?? $record->user->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('email', 'like', "%{$search}%")
                                ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('package_name')
                    ->label('Paquete')
                    ->searchable(),

                Tables\Columns\TextColumn::make('grant_type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => PackageResource::grantTypeLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('grant_value')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (PackageGrant $record): string => "{$record->grant_value} ".(PackageResource::grantValueUnitLabels()[$record->grant_type] ?? '')),

                Tables\Columns\TextColumn::make('admin.name')
                    ->label('Otorgado por')
                    ->getStateUsing(fn (PackageGrant $record): string => $record->admin?->name ?? 'Sistema'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('grant_type')
                    ->label('Tipo')
                    ->options(PackageResource::grantTypeLabels()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackageGrants::route('/'),
        ];
    }
}
