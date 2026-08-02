<?php

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createNotificationUser(): array
{
    $user = User::factory()->withCompletedOnboarding()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'token');
}

describe('GET /api/notifications', function () {
    it('retorna las notificaciones del usuario autenticado en orden descendente', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();

        $older = Notification::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = Notification::factory()->for($user)->create(['created_at' => now()]);

        $response = $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);

        expect($response->json('data.0.id'))->toBe((string) $newer->id);
        expect($response->json('data.1.id'))->toBe((string) $older->id);
    });

    it('no retorna notificaciones de otros usuarios', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        ['user' => $other] = createNotificationUser();

        Notification::factory()->for($other)->create();

        $this->withToken($token)->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('pagina de a 20 por página', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        Notification::factory()->for($user)->count(25)->create();

        $response = $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);

        expect($response->json('data'))->toHaveCount(20);
        expect($response->json('meta.total'))->toBe(25);
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/notifications')->assertStatus(401);
    });
});

describe('PATCH /api/notifications/read-all', function () {
    it('marca todas las notificaciones no leídas como leídas', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        $n1 = Notification::factory()->for($user)->create();
        $n2 = Notification::factory()->for($user)->create();

        $this->withToken($token)->patchJson('/api/notifications/read-all')->assertStatus(200);

        expect($n1->fresh()->read_at)->not->toBeNull();
        expect($n2->fresh()->read_at)->not->toBeNull();
    });

    it('no toca notificaciones de otros usuarios', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        ['user' => $other] = createNotificationUser();
        $othersNotification = Notification::factory()->for($other)->create();

        $this->withToken($token)->patchJson('/api/notifications/read-all')->assertStatus(200);

        expect($othersNotification->fresh()->read_at)->toBeNull();
    });
});

describe('PATCH /api/notifications/{id}/read', function () {
    it('marca una notificación específica como leída', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        $notification = Notification::factory()->for($user)->create();

        $this->withToken($token)->patchJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $notification->id);

        expect($notification->fresh()->read_at)->not->toBeNull();
    });

    it('404 al intentar marcar como leída una notificación de otro usuario', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        ['user' => $other] = createNotificationUser();
        $othersNotification = Notification::factory()->for($other)->create();

        $this->withToken($token)->patchJson("/api/notifications/{$othersNotification->id}/read")
            ->assertStatus(404);
    });
});

describe('GET /api/notifications/unread-count', function () {
    it('retorna el número correcto de no leídas', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        Notification::factory()->for($user)->count(3)->create();
        Notification::factory()->for($user)->read()->count(2)->create();

        $this->withToken($token)->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJson(['count' => 3]);
    });
});

describe('POST /api/notifications/device-token', function () {
    it('guarda el token correctamente', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();

        $this->withToken($token)->postJson('/api/notifications/device-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ]);
    });

    it('no duplica el mismo token (upsert)', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();

        $this->withToken($token)->postJson('/api/notifications/device-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'ios',
        ])->assertStatus(201);

        $this->withToken($token)->postJson('/api/notifications/device-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ])->assertStatus(201);

        expect(DeviceToken::where('user_id', $user->id)->count())->toBe(1);
    });

    it('422 con platform inválida', function () {
        ['token' => $token] = createNotificationUser();

        $this->withToken($token)->postJson('/api/notifications/device-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'windows-phone',
        ])->assertStatus(422)->assertJsonValidationErrors(['platform']);
    });
});

describe('DELETE /api/notifications/device-token', function () {
    it('elimina el/los token(s) del usuario al hacer logout', function () {
        ['user' => $user, 'token' => $token] = createNotificationUser();
        DeviceToken::factory()->for($user)->count(2)->create();

        $this->withToken($token)->deleteJson('/api/notifications/device-token')->assertStatus(204);

        expect(DeviceToken::where('user_id', $user->id)->count())->toBe(0);
    });
});
