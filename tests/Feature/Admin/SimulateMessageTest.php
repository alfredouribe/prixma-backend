<?php

use App\Events\MessageSent;
use App\Filament\Pages\SimulateMessage;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Acceso — restringido a superadmin (herramienta sensible aunque sea de QA)
// ---------------------------------------------------------------------------

it('superadmin puede acceder a la página', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    $this->actingAs($superadmin, 'admin')
        ->get(SimulateMessage::getUrl())
        ->assertSuccessful();
});

it('un admin con role admin no puede acceder', function () {
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')
        ->get(SimulateMessage::getUrl())
        ->assertForbidden();
});

it('un usuario final (guard web) no puede acceder', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user)
        ->get(SimulateMessage::getUrl())
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Envío — reusa ChatService, respeta reglas reales de negocio
// ---------------------------------------------------------------------------

it('crea la conversación como match activo si no existe y envía el mensaje', function () {
    Event::fake([MessageSent::class]);
    $superadmin = Admin::factory()->superadmin()->create();
    $sender = User::factory()->withCompletedOnboarding()->create();
    $recipient = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($superadmin, 'admin');

    Livewire::test(SimulateMessage::class)
        ->fillForm([
            'sender_id' => (string) $sender->id,
            'recipient_id' => (string) $recipient->id,
            'content' => 'Hola, esto es una prueba',
        ])
        ->call('send');

    [$id1, $id2] = $sender->id < $recipient->id ? [$sender->id, $recipient->id] : [$recipient->id, $sender->id];

    $this->assertDatabaseHas('conversations', [
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('messages', [
        'sender_id' => (string) $sender->id,
        'content' => 'Hola, esto es una prueba',
    ]);
});

it('reusa la conversación existente en vez de duplicarla', function () {
    Event::fake([MessageSent::class]);
    $superadmin = Admin::factory()->superadmin()->create();
    $sender = User::factory()->withCompletedOnboarding()->create();
    $recipient = User::factory()->withCompletedOnboarding()->create();

    [$id1, $id2] = $sender->id < $recipient->id ? [$sender->id, $recipient->id] : [$recipient->id, $sender->id];
    $conversation = Conversation::create([
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'active',
    ]);

    $this->actingAs($superadmin, 'admin');

    Livewire::test(SimulateMessage::class)
        ->fillForm([
            'sender_id' => (string) $sender->id,
            'recipient_id' => (string) $recipient->id,
            'content' => 'Segundo mensaje',
        ])
        ->call('send');

    expect(Conversation::count())->toBe(1);
    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('no permite elegir el mismo usuario como emisor y destinatario', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($superadmin, 'admin');

    Livewire::test(SimulateMessage::class)
        ->fillForm([
            'sender_id' => (string) $user->id,
            'recipient_id' => (string) $user->id,
            'content' => 'Hola',
        ])
        ->call('send');

    expect(Message::count())->toBe(0);
});

it('respeta una conversación bloqueada — no envía y no revienta', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $sender = User::factory()->withCompletedOnboarding()->create();
    $recipient = User::factory()->withCompletedOnboarding()->create();

    [$id1, $id2] = $sender->id < $recipient->id ? [$sender->id, $recipient->id] : [$recipient->id, $sender->id];
    Conversation::create([
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'blocked',
    ]);

    $this->actingAs($superadmin, 'admin');

    Livewire::test(SimulateMessage::class)
        ->fillForm([
            'sender_id' => (string) $sender->id,
            'recipient_id' => (string) $recipient->id,
            'content' => 'Esto no debería enviarse',
        ])
        ->call('send');

    expect(Message::count())->toBe(0);
});
