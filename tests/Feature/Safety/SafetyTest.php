<?php

use App\Models\Block;
use App\Models\Conversation;
use App\Models\GeographicBlock;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;

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

    it('reporte duplicado al mismo usuario no lanza error', function () {
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

        // Solo se conserva el reporte más reciente para el mismo par.
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $this->user->id,
            'reported_id' => $target->id,
            'reason'      => 'fake_profile',
            'status'      => 'pending',
        ]);
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
