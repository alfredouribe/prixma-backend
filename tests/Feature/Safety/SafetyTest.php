<?php

use App\Models\Block;
use App\Models\Conversation;
use App\Models\GenderIdentity;
use App\Models\GeographicBlock;
use App\Models\Interest;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;
use App\Services\SafetyService;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createSafetyUser(array $profileData = []): array
{
    $user = User::factory()->withCompletedOnboarding()->create();

    $profile = Profile::create(array_merge([
        'user_id'              => $user->id,
        'display_name'         => fake()->name(),
        'city'                 => 'CDMX',
        'intention'            => 'friendship',
        'onboarding_step'      => 6,
        'onboarding_completed' => true,
    ], $profileData));

    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'profile', 'token');
}

beforeEach(function () {
    $this->seed([
        GenderIdentitySeeder::class,
        OrientationSeeder::class,
        PronounSeeder::class,
        InterestSeeder::class,
    ]);

    ['user' => $this->user, 'profile' => $this->profile, 'token' => $this->token] = createSafetyUser();
});

// ---------------------------------------------------------------------------
// POST /api/safety/reports
// ---------------------------------------------------------------------------

describe('reports', function () {

    it('crea un reporte con status pending', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
                'description' => 'Comentarios ofensivos repetidos.',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $this->user->id,
            'reported_id' => $target->id,
            'reason'      => 'harassment',
            'status'      => 'pending',
        ]);
    });

    it('reporte duplicado al mismo usuario crea una fila nueva cada vez', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(201);

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'fake_profile',
                'description' => 'En realidad es una cuenta falsa.',
            ])
            ->assertStatus(201);

        // Cada reporte es una fila nueva con su propia evidencia — ya no es
        // idempotente (Report::create(), no updateOrCreate()).
        $this->assertDatabaseCount('reports', 2);
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $this->user->id,
            'reported_id' => $target->id,
            'reason'      => 'harassment',
            'status'      => 'pending',
        ]);
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $this->user->id,
            'reported_id' => $target->id,
            'reason'      => 'fake_profile',
            'status'      => 'pending',
        ]);
    });

    it('crear reporte también crea bloqueo en la misma transacción', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('blocks', [
            'blocker_id' => $this->user->id,
            'blocked_id' => $target->id,
        ]);
    });

    it('si falla la creación del reporte, no se crea el bloqueo (rollback de transacción)', function () {
        $safetyService = app(SafetyService::class);
        $nonExistentReportedId = (string) \Illuminate\Support\Str::uuid();

        expect(fn () => $safetyService->createReport(
            $this->user,
            $nonExistentReportedId,
            ['reason' => 'harassment'],
        ))->toThrow(ModelNotFoundException::class);

        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('blocks', 0);
    });

    it('reporte guarda snapshot del perfil correctamente', function () {
        $identity = GenderIdentity::first();
        $interest = Interest::first();

        $target = User::factory()->withCompletedOnboarding()->create();
        $targetProfile = Profile::create([
            'user_id'              => $target->id,
            'display_name'         => 'Perfil Reportado',
            'bio'                  => 'Bio del reportado.',
            'city'                 => 'GDL',
            'intention'            => 'partner',
            'onboarding_step'      => 6,
            'onboarding_completed' => true,
        ]);
        $targetProfile->genderIdentities()->attach($identity->id);
        $targetProfile->interests()->attach($interest->id);
        ProfilePhoto::create([
            'profile_id' => $targetProfile->id,
            'url'        => 'https://s3.example.com/profiles/photos/target/1.jpg',
            'key'        => 'profiles/photos/target/1.jpg',
            'position'   => 0,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(201);

        $report = Report::where('reporter_id', $this->user->id)
            ->where('reported_id', $target->id)
            ->firstOrFail();

        expect($report->profile_snapshot['display_name'])->toBe('Perfil Reportado');
        expect($report->profile_snapshot['bio'])->toBe('Bio del reportado.');
        expect($report->profile_snapshot['photos'])->toBe(['https://s3.example.com/profiles/photos/target/1.jpg']);
        expect($report->profile_snapshot['gender_identities'])->toBe([$identity->label]);
        expect($report->profile_snapshot['interests'])->toBe([$interest->label]);
    });

    it('reporte guarda últimos 20 mensajes si existe conversación', function () {
        ['user' => $target] = createSafetyUser();

        $conversation = Conversation::factory()->betweenUsers($this->user, $target)->create();

        foreach (range(0, 24) as $i) {
            Message::factory()->for($conversation)->create([
                'sender_id'  => $this->user->id,
                'content'    => "mensaje-{$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(201);

        $report = Report::where('reporter_id', $this->user->id)
            ->where('reported_id', $target->id)
            ->firstOrFail();

        expect($report->chat_snapshot)->toHaveCount(20);
        expect($report->chat_snapshot[0]['content'])->toBe('mensaje-5');
        expect($report->chat_snapshot[19]['content'])->toBe('mensaje-24');
    });

    it('reporte funciona aunque no haya conversación previa', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(201);

        $report = Report::where('reporter_id', $this->user->id)
            ->where('reported_id', $target->id)
            ->firstOrFail();

        expect($report->chat_snapshot)->toBe([]);
    });

    it('rechaza reportarse a sí mismo', function () {
        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $this->user->id,
                'reason'      => 'harassment',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reported_id']);
    });

    it('rechaza motivo inválido', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'not_a_real_reason',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    it('rechaza descripción mayor a 300 caracteres', function () {
        ['user' => $target] = createSafetyUser();

        $this->withToken($this->token)
            ->postJson('/api/safety/reports', [
                'reported_id' => $target->id,
                'reason'      => 'other',
                'description' => str_repeat('a', 301),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    });

    it('request sin autenticación retorna 401', function () {
        ['user' => $target] = createSafetyUser();

        $this->postJson('/api/safety/reports', [
            'reported_id' => $target->id,
            'reason'      => 'harassment',
        ])->assertUnauthorized();
    });

});

// ---------------------------------------------------------------------------
// SafetyService::banReportedUser() / unbanUser() — solo lo usa el panel
// admin (ReportResource/UserResource), sin endpoint móvil. Mismo criterio
// que markReportAsReviewed()/markReportAsResolved(): se prueba el Service
// directo aquí (efectos secundarios reales: tokens, correo, auto-resolve);
// la visibilidad/autorización del botón en Filament se prueba en
// ReportResourceTest.php/UserResourceTest.php.
// ---------------------------------------------------------------------------

describe('banReportedUser / unbanUser (SafetyService)', function () {

    it('banea al usuario reportado: status=banned, revoca tokens, resuelve el reporte y envía el correo', function () {
        \Illuminate\Support\Facades\Mail::fake();

        ['user' => $reported] = createSafetyUser();
        // createSafetyUser() ya emite un token ('mobile') al crear el
        // usuario — se agregan 2 más para simular varios dispositivos con
        // sesión activa.
        $reported->createToken('device-1');
        $reported->createToken('device-2');
        expect($reported->tokens()->count())->toBe(3);

        $report = Report::create([
            'reporter_id' => $this->user->id,
            'reported_id' => $reported->id,
            'reason'      => 'harassment',
            'status'      => 'pending',
        ]);

        $result = app(SafetyService::class)->banReportedUser($report, 'Acoso');

        expect($reported->fresh()->status)->toBe('banned')
            ->and($reported->tokens()->count())->toBe(0)
            ->and($result->status)->toBe('resolved');

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\UserBannedMail::class, function ($mail) use ($reported) {
            return $mail->hasTo($reported->email) && $mail->reasonLabel === 'Acoso';
        });
    });

    it('un usuario baneado ya no puede iniciar sesión, ni siquiera con el token que ya tenía', function () {
        ['user' => $reported] = createSafetyUser();
        $oldToken = $reported->createToken('device-1')->plainTextToken;

        $report = Report::create([
            'reporter_id' => $this->user->id,
            'reported_id' => $reported->id,
            'reason'      => 'harassment',
            'status'      => 'pending',
        ]);

        app(SafetyService::class)->banReportedUser($report, 'Acoso');

        $this->postJson('/api/auth/login', [
            'email'    => $reported->email,
            'password' => 'password',
        ])->assertStatus(403);

        $this->withToken($oldToken)
            ->getJson('/api/profiles/me')
            ->assertUnauthorized();
    });

    it('unbanUser revierte el status a active', function () {
        ['user' => $reported] = createSafetyUser();
        $reported->update(['status' => 'banned']);

        $result = app(SafetyService::class)->unbanUser($reported);

        expect($result->status)->toBe('active')
            ->and($reported->fresh()->status)->toBe('active');
    });

});

// ---------------------------------------------------------------------------
// POST /api/safety/blocks — bidireccionalidad + integración con Matching
// ---------------------------------------------------------------------------

describe('blocks', function () {

    it('bloqueo es bidireccional: ninguno aparece en el explore del otro', function () {
        ['user' => $blocked, 'token' => $blockedToken] = createSafetyUser([
            'verification_status' => 'verified',
        ]);
        $this->user->profile->update(['verification_status' => 'verified']);

        $this->withToken($this->token)
            ->postJson('/api/safety/blocks', ['blocked_id' => $blocked->id])
            ->assertStatus(201);

        // A (this->user) no ve a B (blocked) en su explore.
        $idsForA = collect(
            $this->withToken($this->token)->getJson('/api/matching/explore')->json('data')
        )->pluck('id');
        expect($idsForA)->not->toContain((string) $blocked->id);

        // B (blocked) no ve a A (this->user) en su explore.
        $idsForB = collect(
            $this->withToken($blockedToken)->getJson('/api/matching/explore')->json('data')
        )->pluck('id');
        expect($idsForB)->not->toContain((string) $this->user->id);
    });

    it('bloquear anula el match existente entre ambos', function () {
        ['user' => $other] = createSafetyUser();

        [$id1, $id2] = $this->user->id < $other->id
            ? [$this->user->id, $other->id]
            : [$other->id, $this->user->id];

        UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);
        $this->assertDatabaseCount('matches', 1);

        $this->withToken($this->token)
            ->postJson('/api/safety/blocks', ['blocked_id' => $other->id])
            ->assertStatus(201);

        $this->assertDatabaseCount('matches', 0);
    });

    it('bloquear oculta la conversación existente para ambos sin eliminarla', function () {
        ['user' => $other] = createSafetyUser();

        [$id1, $id2] = $this->user->id < $other->id
            ? [$this->user->id, $other->id]
            : [$other->id, $this->user->id];

        $match = UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type'      => 'match',
            'status'    => 'active',
            'match_id'  => $match->id,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/safety/blocks', ['blocked_id' => $other->id])
            ->assertStatus(201);

        $this->assertDatabaseHas('conversations', [
            'id'     => $conversation->id,
            'status' => 'blocked',
        ]);
    });

    it('rechaza bloquearse a sí mismo', function () {
        $this->withToken($this->token)
            ->postJson('/api/safety/blocks', ['blocked_id' => $this->user->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocked_id']);
    });

    it('lista solo los bloqueos del usuario autenticado', function () {
        ['user' => $blockedByMe] = createSafetyUser();
        ['user' => $otherBlocker, 'token' => $otherToken] = createSafetyUser();
        ['user' => $blockedByOther] = createSafetyUser();

        Block::create(['blocker_id' => $this->user->id, 'blocked_id' => $blockedByMe->id]);
        Block::create(['blocker_id' => $otherBlocker->id, 'blocked_id' => $blockedByOther->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/safety/blocks')
            ->assertStatus(200);

        $data = collect($response->json('data'));
        expect($data)->toHaveCount(1);
        expect($data->pluck('blocked_user.id'))->toContain((string) $blockedByMe->id);
    });

    it('permite desbloquear y elimina el registro', function () {
        ['user' => $other] = createSafetyUser();

        $block = Block::create(['blocker_id' => $this->user->id, 'blocked_id' => $other->id]);

        $this->withToken($this->token)
            ->deleteJson("/api/safety/blocks/{$block->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    });

    it('no permite desbloquear un registro ajeno', function () {
        ['user' => $otherBlocker, 'token' => $otherToken] = createSafetyUser();
        ['user' => $target] = createSafetyUser();

        $block = Block::create(['blocker_id' => $otherBlocker->id, 'blocked_id' => $target->id]);

        $this->withToken($this->token)
            ->deleteJson("/api/safety/blocks/{$block->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    });

    it('request sin autenticación retorna 401', function () {
        ['user' => $target] = createSafetyUser();

        $this->postJson('/api/safety/blocks', ['blocked_id' => $target->id])
            ->assertUnauthorized();
    });

});

// ---------------------------------------------------------------------------
// GET/POST/DELETE /api/safety/geo-blocks
// ---------------------------------------------------------------------------

describe('geo blocks', function () {

    it('crea un bloqueo geográfico', function () {
        $this->withToken($this->token)
            ->postJson('/api/safety/geo-blocks', [
                'label'     => 'Mi colonia',
                'latitude'  => 19.4326,
                'longitude' => -99.1332,
                'radius_km' => 5,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.label', 'Mi colonia')
            ->assertJsonPath('data.radius_km', 5);

        $this->assertDatabaseHas('geographic_blocks', [
            'user_id' => $this->user->id,
            'label'   => 'Mi colonia',
        ]);
    });

    it('rechaza radio fuera de rango', function () {
        $this->withToken($this->token)
            ->postJson('/api/safety/geo-blocks', [
                'latitude'  => 19.4326,
                'longitude' => -99.1332,
                'radius_km' => 51,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['radius_km']);
    });

    it('geo blocks se listan solo del usuario autenticado', function () {
        ['user' => $other, 'token' => $otherToken] = createSafetyUser();

        GeographicBlock::create([
            'user_id' => $this->user->id, 'latitude' => 19.4326, 'longitude' => -99.1332, 'radius_km' => 5,
        ]);
        GeographicBlock::create([
            'user_id' => $other->id, 'latitude' => 20.6597, 'longitude' => -103.3496, 'radius_km' => 10,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/safety/geo-blocks')
            ->assertStatus(200);

        expect($response->json('data'))->toHaveCount(1);

        $responseOther = $this->withToken($otherToken)
            ->getJson('/api/safety/geo-blocks')
            ->assertStatus(200);

        expect($responseOther->json('data'))->toHaveCount(1);
    });

    it('permite eliminar solo el propio bloqueo geográfico', function () {
        ['user' => $other, 'token' => $otherToken] = createSafetyUser();

        $mine = GeographicBlock::create([
            'user_id' => $this->user->id, 'latitude' => 19.4326, 'longitude' => -99.1332, 'radius_km' => 5,
        ]);
        $theirs = GeographicBlock::create([
            'user_id' => $other->id, 'latitude' => 20.6597, 'longitude' => -103.3496, 'radius_km' => 10,
        ]);

        $this->withToken($this->token)
            ->deleteJson("/api/safety/geo-blocks/{$theirs->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('geographic_blocks', ['id' => $theirs->id]);

        $this->withToken($this->token)
            ->deleteJson("/api/safety/geo-blocks/{$mine->id}")
            ->assertStatus(204);
        $this->assertDatabaseMissing('geographic_blocks', ['id' => $mine->id]);
    });

    it('request sin autenticación retorna 401', function () {
        $this->postJson('/api/safety/geo-blocks', [
            'latitude' => 19.4326, 'longitude' => -99.1332, 'radius_km' => 5,
        ])->assertUnauthorized();
    });

});
