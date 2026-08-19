<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Paquetes';

    protected static ?string $modelLabel = 'paquete';

    protected static ?string $pluralModelLabel = 'paquetes';

    /**
     * Igual que EventResource, este es un catálogo administrado por staff
     * (CRUD completo) — no de solo lectura como UserResource/ReportResource.
     * Ver features/premium/specs/plan.md → "Catálogo de paquetes" → Filament.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label('Descripción')
                ->nullable()
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('price')
                ->label('Precio')
                ->prefix('$')
                ->numeric()
                ->minValue(0)
                ->required(),

            Select::make('grant_type')
                ->label('Tipo de recompensa')
                ->options(static::grantTypeLabels())
                ->required()
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('grant_value', null)),

            TextInput::make('grant_value')
                ->label('Cantidad')
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText(fn (Get $get): string => static::grantValueUnitLabels()[$get('grant_type')] ?? ''),

            Toggle::make('is_active')
                ->label('Activo')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('grant_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'premium_days' => 'warning',
                        'boost_minutes' => 'info',
                        'see_likers_days' => 'purple',
                        'rewind_credits' => 'success',
                        'super_like_credits' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::grantTypeLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('grant_value')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (Package $record): string => "{$record->grant_value} ".(static::grantValueUnitLabels()[$record->grant_type] ?? '')),

                Tables\Columns\TextColumn::make('price')
                    ->label('Precio')
                    ->money('mxn')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('grant_type')
                    ->label('Tipo')
                    ->options(static::grantTypeLabels()),

                TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }

    /**
     * Ver domain.md → Package y features/premium/specs/spec.md → "Catálogo
     * de paquetes" para los 4 tipos confirmados con el humano.
     */
    public static function grantTypeLabels(): array
    {
        return [
            'premium_days' => 'Premium por tiempo limitado',
            'boost_minutes' => 'Boost de perfil',
            'see_likers_days' => 'Ver quién te dio like',
            'rewind_credits' => 'Deshacer swipe (rewind)',
            'super_like_credits' => 'Super likes extra',
        ];
    }

    /**
     * Unidad del helper text dinámico de `grant_value` según `grant_type` —
     * ver plan.md → "Filament" → "helper text dinámico".
     */
    public static function grantValueUnitLabels(): array
    {
        return [
            'premium_days' => 'días',
            'boost_minutes' => 'minutos',
            'see_likers_days' => 'días',
            'rewind_credits' => 'créditos',
            'super_like_credits' => 'créditos',
        ];
    }
}
