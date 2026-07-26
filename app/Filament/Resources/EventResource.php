<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Models\Event;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Eventos';

    protected static ?string $modelLabel = 'evento';

    protected static ?string $pluralModelLabel = 'eventos';

    /**
     * A diferencia de ReportResource/UserResource/VerificationRequestResource
     * (todos de solo lectura o sin creación), Event sí es CRUD completo — es
     * la única entidad de negocio que el staff crea directamente desde el
     * panel (ver domain.md → Event, spec.md → "Creación de eventos").
     *
     * `withCount` calcula los contadores de asistentes una sola vez aquí
     * (server-side, mismo patrón que EventService::list()/find() del lado
     * móvil) en vez de contarlos en PHP tras cargar la colección completa —
     * ver constitution.md → "Server-side search, filter, and pagination".
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount([
                'rsvps as interested_count' => fn (Builder $q) => $q->where('status', 'interested'),
                'rsvps as going_count' => fn (Builder $q) => $q->where('status', 'going'),
                'rsvps as not_going_count' => fn (Builder $q) => $q->where('status', 'not_going'),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(100),

            Textarea::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(500)
                ->rows(4)
                ->columnSpanFull(),

            Select::make('category')
                ->label('Categoría')
                ->options(static::categoryLabels())
                ->required(),

            DateTimePicker::make('event_date')
                ->label('Fecha y hora')
                ->required()
                ->native(false)
                ->seconds(false),

            TextInput::make('location_name')
                ->label('Ubicación / dirección')
                ->required()
                ->maxLength(255),

            // Sin mapa interactivo en v1 — el proyecto no tiene ningún
            // componente de mapa del lado Filament (react-native-maps es
            // exclusivo del lado móvil, ver constitution.md → "Mapas
            // visuales"). Inputs numéricos simples, mismo rango que
            // GeoBlockRequest/UpdateProfileRequest del lado móvil (ver
            // domain.md → Event: decimal(10,8)/decimal(11,8)). Documentado
            // en features/events/specs/plan.md.
            TextInput::make('latitude')
                ->label('Latitud')
                ->numeric()
                ->minValue(-90)
                ->maxValue(90)
                ->nullable(),

            TextInput::make('longitude')
                ->label('Longitud')
                ->numeric()
                ->minValue(-180)
                ->maxValue(180)
                ->nullable(),

            TextInput::make('external_link')
                ->label('Link externo')
                ->url()
                ->nullable()
                ->maxLength(255),

            // El propio backend/admin sube el archivo (no hay un cliente
            // externo multipart de por medio) — Filament sube directo al
            // disco `s3` configurado, sin replicar el pipeline
            // multipart-cliente→ffmpeg→S3 completo de constitution.md (ese
            // pipeline existe para blindar contra clientes no confiables;
            // aquí quien sube es staff autenticado del panel). Decisión
            // documentada en features/events/specs/plan.md. Sin compresión
            // ffmpeg en v1 (ver mismo documento para el razonamiento).
            //
            // Corrección 2026-07-26: `->visibility('private')` explícito
            // (no solo "sin visibility"). El bucket real (`prixma`) tiene
            // los ACLs deshabilitados ("Bucket owner enforced") — cualquier
            // PutObject que pida ACL público falla con
            // `AccessControlListNotSupported`, y con el disco `s3` en
            // `'throw' => false` (config/filesystems.php) el fallo quedaba
            // silencioso: Filament mostraba "éxito" pero el archivo nunca
            // llegaba al bucket. Confirmado en tinker forzando
            // `'throw' => true` contra el disco real; `'private'` sí sube
            // sin problema. Además, `visibility('private')` es necesario
            // por una segunda razón (bug real encontrado tras el primer
            // fix): sin él, Filament asume `'public'` por defecto
            // (`BaseFileUpload::$visibility`) y genera el preview del
            // archivo ya subido con `Storage::url()` (URL plana, sin
            // firmar) — en un bucket privado esa URL da 403, y el campo se
            // ve "vacío" en el formulario de edición aunque `image_key` sí
            // esté guardado en la base de datos. Con `'private'`, Filament
            // usa `Storage::temporaryUrl()` para el preview
            // (`BaseFileUpload::getUploadedFileUrlUsing`), igual que
            // `ProfileService::compressAndUploadToS3()` (que tampoco pide
            // ACL) y que `EventResource` — API móvil — con `video_url`.
            FileUpload::make('image_key')
                ->label('Imagen del evento')
                ->image()
                ->disk('s3')
                ->directory('events')
                ->visibility('private')
                ->maxSize(5120)
                ->nullable()
                ->columnSpanFull(),

            // interested_count / going_count / not_going_count son
            // calculados (withCount arriba) — nunca aparecen en el
            // formulario de creación/edición, ver spec.md → "Panel de
            // administración".
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pride' => 'purple',
                        'social' => 'info',
                        'art' => 'warning',
                        'activism' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::categoryLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('event_date')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('location_name')
                    ->label('Ubicación')
                    ->limit(30),

                // Solo interested + going — not_going nunca se muestra como
                // contador público, mismo criterio que EventResource (API
                // móvil) y spec.md → "Estados de asistencia".
                Tables\Columns\TextColumn::make('attendees_count')
                    ->label('Asistentes')
                    ->getStateUsing(fn (Event $record): int => $record->interested_count + $record->going_count),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(static::categoryLabels()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('event_date', 'asc')
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
            'view' => Pages\ViewEvent::route('/{record}'),
        ];
    }

    /**
     * Labels en español ya aprobados en brand/copies.md → "Eventos" →
     * Categorías.
     */
    public static function categoryLabels(): array
    {
        return [
            'pride' => 'Pride',
            'social' => 'Social',
            'art' => 'Arte',
            'activism' => 'Activismo',
        ];
    }
}
