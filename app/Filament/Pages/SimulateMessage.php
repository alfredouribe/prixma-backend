<?php

namespace App\Filament\Pages;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Herramienta interna de QA — no es una feature de producto. Permite a
 * staff enviar un mensaje de chat "como si fuera" cualquier usuario real,
 * sin necesitar dos dispositivos/sesiones logueadas para probar flujos que
 * dependen de un mensaje real llegando (ej. push de Notifications vía
 * NotificationService::sendMessageNotification()). Reusa ChatService tal
 * cual — no bypassea reglas de negocio (conversación bloqueada/rechazada
 * sigue rechazando el envío igual que en producción).
 *
 * Restringida a superadmin — permite mandar mensajes suplantando a
 * cualquier usuario, sensible aunque sea para pruebas (confirmado con el
 * humano 2026-08-01).
 */
class SimulateMessage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Herramientas';

    protected static ?string $navigationLabel = 'Simular mensaje';

    protected static ?string $title = 'Simular mensaje entre usuarios';

    protected static string $view = 'filament.pages.simulate-message';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin && $admin->role === 'superadmin';
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('sender_id')
                    ->label('Enviado por')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::searchUsers($search))
                    ->getOptionLabelUsing(fn ($value) => self::userLabel(User::find($value)))
                    ->required(),

                Select::make('recipient_id')
                    ->label('Destinatario')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::searchUsers($search))
                    ->getOptionLabelUsing(fn ($value) => self::userLabel(User::find($value)))
                    ->required(),

                Textarea::make('content')
                    ->label('Mensaje')
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->statePath('data');
    }

    public function send(ChatService $chatService): void
    {
        $formData = $this->form->getState();

        if ($formData['sender_id'] === $formData['recipient_id']) {
            Notification::make()
                ->title('El emisor y el destinatario deben ser usuarios distintos.')
                ->danger()
                ->send();

            return;
        }

        $sender = User::findOrFail($formData['sender_id']);
        $recipientId = $formData['recipient_id'];

        // Mismo orden que ChatService/MatchingService: user_id_1 siempre el
        // UUID menor. Si ya existe una conversación (con cualquier
        // status/type), firstOrCreate() la retorna tal cual — no se
        // sobreescribe un status real (ej. blocked/rejected) solo porque
        // esta herramienta quiere mandar un mensaje.
        [$id1, $id2] = $sender->id < $recipientId ? [$sender->id, $recipientId] : [$recipientId, $sender->id];

        $conversation = Conversation::firstOrCreate(
            ['user_id_1' => $id1, 'user_id_2' => $id2],
            ['type' => 'match', 'status' => 'active'],
        );

        try {
            $chatService->sendMessage($sender, $conversation->id, $formData['content']);
        } catch (BusinessException|AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Mensaje enviado.')->success()->send();

        $this->form->fill(['content' => null]);
    }

    /**
     * @return array<string, string>
     */
    private static function searchUsers(string $search): array
    {
        return User::query()
            ->with('profile')
            ->where(function ($query) use ($search) {
                $query->where('email', 'like', "%{$search}%")
                    ->orWhereHas('profile', fn ($p) => $p->where('display_name', 'like', "%{$search}%"));
            })
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => self::userLabel($user)])
            ->all();
    }

    private static function userLabel(?User $user): string
    {
        if (!$user) {
            return '';
        }

        return ($user->profile?->display_name ?? 'Sin perfil') . " — {$user->email}";
    }
}
