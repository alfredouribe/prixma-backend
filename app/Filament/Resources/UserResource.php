<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Package;
use App\Models\User;
use App\Services\PackageService;
use App\Services\SafetyService;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    /**
     * Eager load para evitar N+1 en la tabla (profile.display_name para el
     * nombre con fallback a email, profile.verification_status para el
     * badge de verificación) y withCount para el contador de reportes
     * recibidos en la lista — mismo criterio que ReportResource/
     * VerificationRequestResource. Las relaciones de catálogo
     * (genderIdentities/orientations/pronouns) solo las usa el detalle
     * (`ViewUser`), pero se cargan aquí también porque ambas páginas
     * comparten esta misma query base — mismo criterio que
     * `ReportResource::getEloquentQuery()` extendiendo a `reported.profile.photos`
     * solo para el detalle.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'profile.photos',
                'profile.genderIdentities',
                'profile.orientations',
                'profile.pronouns',
            ])
            ->withCount('reportsReceived');
    }

    // Los usuarios finales no se crean ni editan desde el panel — el
    // recurso es de solo lectura (alcance confirmado con el humano, ver
    // features/profile/specs/plan.md → "Panel admin — gestión de usuarios").
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('profile.display_name')
                    ->label('Nombre')
                    ->getStateUsing(fn (User $record): string => $record->profile?->display_name ?? $record->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('email', 'like', "%{$search}%")
                            ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$search}%"));
                    }),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado de cuenta')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'banned' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::statusLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('profile.verification_status')
                    ->label('Verificación')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $record->profile?->verification_status ?? 'unverified')
                    ->color(fn (string $state): string => match ($state) {
                        'unverified' => 'gray',
                        'pending' => 'warning',
                        'verified' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::verificationLabels()[$state] ?? $state),

                Tables\Columns\IconColumn::make('is_premium')
                    ->label('Premium')
                    ->boolean(),

                Tables\Columns\TextColumn::make('reports_received_count')
                    ->label('Reportes recibidos')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado de cuenta')
                    ->options(static::statusLabels()),

                SelectFilter::make('verification_status')
                    ->label('Verificación')
                    ->options(static::verificationLabels())
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, string $value): Builder => $q->whereHas(
                                'profile',
                                fn (Builder $p) => $p->where('verification_status', $value),
                            ),
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Única excepción al "solo lectura" de este recurso (ver
                // comentario en form() arriba) — features/premium/specs/
                // necesita una forma de marcar usuarios como Premium
                // mientras no exista compra real. Acción puntual y
                // confirmada, no un formulario de edición general.
                Tables\Actions\Action::make('togglePremium')
                    ->label(fn (User $record): string => $record->is_premium ? 'Quitar Premium' : 'Activar Premium')
                    ->icon('heroicon-o-star')
                    ->color(fn (User $record): string => $record->is_premium ? 'gray' : 'warning')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update(['is_premium' => !$record->is_premium])),

                // Segunda excepción al "solo lectura" de este recurso (ver
                // comentario en form() arriba) — reversa un ban aplicado
                // desde ReportResource\Pages\ViewReport (acción `banUser`),
                // por si una apelación a support@prixma.site resulta
                // legítima. Mismo patrón que togglePremium: sin ->authorize()
                // propia (visible/ejecutable para cualquier admin autenticado
                // que ya llegó a este recurso).
                Tables\Actions\Action::make('unban')
                    ->label('Quitar ban')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->status === 'banned')
                    ->action(fn (User $record) => app(SafetyService::class)->unbanUser($record)),

                // Tercera excepción al "solo lectura" de este recurso (ver
                // comentario en form() arriba) — features/premium/specs/
                // "Catálogo de paquetes" (2026-08-11): otorgar manualmente un
                // paquete del catálogo a un usuario específico, mientras no
                // exista Google Play Billing/Apple IAP. Solo paquetes
                // `is_active` aparecen en el select. Delega por completo en
                // PackageService::grantToUser() — la Resource solo resuelve
                // el Package elegido y llama al Service, ninguna lógica de
                // negocio vive aquí (constitution.md → "No business logic in
                // the panel").
                Tables\Actions\Action::make('grantPackage')
                    ->label('Otorgar paquete')
                    ->icon('heroicon-o-gift')
                    ->color('primary')
                    ->form([
                        Select::make('package_id')
                            ->label('Paquete')
                            ->options(fn (): array => Package::where('is_active', true)->pluck('name', 'id')->all())
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $package = Package::findOrFail($data['package_id']);

                        app(PackageService::class)->grantToUser($record, $package, auth('admin')->user());

                        Notification::make()
                            ->title('Paquete otorgado')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
        ];
    }

    /**
     * Sin copy aprobado en brand/copies.md para estos 3 estados (ese archivo
     * cubre texto de cara al usuario final, no tooling interno de staff —
     * el panel admin está exento por convención ya establecida en el
     * proyecto). Se usan formas femeninas concordando con "la cuenta"
     * (Activa/Suspendida/Baneada) en vez de inventar un neologismo de
     * género neutro sin aprobación de marca — decisión documentada en
     * features/profile/specs/plan.md.
     */
    public static function statusLabels(): array
    {
        return [
            'active' => 'Activa',
            'suspended' => 'Suspendida',
            'banned' => 'Baneada',
        ];
    }

    public static function verificationLabels(): array
    {
        return [
            'unverified' => 'Sin verificar',
            'pending' => 'Pendiente',
            'verified' => 'Verificado',
            'rejected' => 'Rechazado',
        ];
    }
}
