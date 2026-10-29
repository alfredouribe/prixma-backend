<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\PhotosRelationManager;
use App\Models\Package;
use App\Models\User;
use App\Services\PackageService;
use App\Services\SafetyService;
use App\Services\VerificationService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                // Cuarta excepción al "solo lectura" de este recurso (ver
                // comentario en form() arriba) — banear directo sin que
                // exista un Report previo (a diferencia de
                // ReportResource\Pages\ViewReport::banUser, que saca el
                // motivo del `reason` del reporte, aquí no hay reporte así
                // que el motivo lo escribe el staff a mano). Delega por
                // completo en SafetyService::banUser() (standalone, sin
                // depender de ningún Report) — ninguna lógica de negocio
                // vive aquí.
                Tables\Actions\Action::make('banUser')
                    ->label('Banear usuario')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('¿Confirmas que quieres banear a este usuario? Su cuenta quedará deshabilitada de inmediato (no podrá iniciar sesión ni seguir usando una sesión activa) y se le enviará un correo notificándole el motivo que escribas abajo. Esta acción se puede revertir después con "Quitar ban".')
                    ->visible(fn (User $record): bool => $record->status !== 'banned')
                    ->form([
                        Textarea::make('reason')
                            ->label('Motivo')
                            ->required()
                            ->maxLength(300),
                    ])
                    ->action(function (User $record, array $data): void {
                        app(SafetyService::class)->banUser($record, $data['reason']);

                        Notification::make()
                            ->title('Usuario baneado')
                            ->success()
                            ->send();
                    }),

                // Quinta excepción al "solo lectura" de este recurso (ver
                // comentario en form() arriba) — reinicia la verificación de
                // identidad de un usuario a "No verificado" (ej. para que
                // pueda volver a subir su documento tras un bug, sin tener
                // que crear una cuenta nueva). Delega por completo en
                // VerificationService::resetVerification() (borra archivos
                // huérfanos en disco y resetea el campo denormalizado
                // profiles.verification_status) — ninguna lógica de negocio
                // vive aquí. Oculta si el usuario ya está "Sin verificar",
                // mismo criterio que `unban` solo visible si está baneado.
                Tables\Actions\Action::make('resetVerification')
                    ->label('Reiniciar verificación')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('¿Confirmas que quieres reiniciar la verificación de este usuario? Su estado volverá a "No verificado" y podrá subir su documento de identificación de nuevo. El historial de solicitudes anteriores no se borra.')
                    ->visible(fn (User $record): bool => ($record->profile?->verification_status ?? 'unverified') !== 'unverified')
                    ->action(function (User $record): void {
                        app(VerificationService::class)->resetVerification($record);

                        Notification::make()
                            ->title('Verificación reiniciada')
                            ->success()
                            ->send();
                    }),

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
     * Permite borrar una foto puntual del perfil del usuario desde el detalle
     * (ver features/profile/specs/plan.md → "Eliminar foto específica") — un
     * Infolist (la sección "Fotos" de ViewUser) es de solo lectura por
     * diseño en Filament, no soporta una Action por fila; un RelationManager
     * sí es una Table real con soporte completo de Actions por fila, el
     * patrón idiomático de Filament 3 para esto. La sección de solo lectura
     * de ViewUser se deja intacta (complementa, no reemplaza) — sigue
     * sirviendo como vista rápida sin tabs, y no rompe los tests ya
     * existentes que la cubren.
     */
    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
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
