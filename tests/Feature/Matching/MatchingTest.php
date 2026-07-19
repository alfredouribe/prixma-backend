<?php

use App\Models\Conversation;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\UserMatch;
use App\Models\Swipe;
use App\Models\User;
use App\Services\MatchingService;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createUserWithProfile(array $profileData = [], array $userState = []): array
{
    $user = User::factory()
        ->withCompletedOnboarding()
        ->create($userState);

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

    // El actor ($this->user, quien hace las peticiones vía $this->token) debe
    // estar verificado: getExploreQueue()/recordSwipe() ahora exigen
    // verification_status === 'verified' antes de cualquier otra lógica (gate
    // de verificación en backend, ver spec.md → "Gate de verificación (backend)").
    // Los candidatos creados dentro de cada test siguen usando el default
    // 'unverified' de createUserWithProfile() salvo que el test lo override.
    ['user' => $this->user, 'profile' => $this->profile, 'token' => $this->token] = createUserWithProfile([
        'verification_status' => 'verified',
    ]);
});

// ---------------------------------------------------------------------------
// GET /api/matching/explore
// ---------------------------------------------------------------------------

describe('explore', function () {

    it('retorna batch de perfiles', function () {
        ['user' => $other] = createUserWithProfile();

        $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    });

    it('retorna gender_identities, orientations e interests como labels legibles, y el campo bio', function () {
        $genderIdentity = \App\Models\GenderIdentity::first();
        $orientation    = \App\Models\SexualOrientation::first();
        $interest       = Interest::first();

        ['profile' => $candidateProfile] = createUserWithProfile([
            'bio' => 'Amante del café y las plantas',
        ]);
        $candidateProfile->genderIdentities()->attach($genderIdentity->id);
        $candidateProfile->orientations()->attach($orientation->id);
        $candidateProfile->interests()->attach($interest->id);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $card = collect($response->json('data'))->firstWhere('bio', 'Amante del café y las plantas');

        expect($card)->not->toBeNull();
        expect($card['gender_identities'])->toContain($genderIdentity->label);
        expect($card['orientations'])->toContain($orientation->label);
        expect($card['interests'])->toContain($interest->label);

        // No deben filtrarse slugs crudos donde van labels
        expect($card['gender_identities'])->not->toContain($genderIdentity->slug);
        expect($card['orientations'])->not->toContain($orientation->slug);
        expect($card['interests'])->not->toContain($interest->slug);
    });

    it('retorna los datos de perfil correctos para múltiples candidatos reales', function () {
        // Regresión: profiles.* en el select colisionaba con users.id
        // (mismo alias `id`), corrompiendo la PK del User hidratado y
        // dejando $candidate->profile en null → TypeError en calculateScore().
        //
        // Se crean las preferencias del viewer explícitamente (en vez de
        // dejar que getPreferences() las cree de forma perezosa) para
        // aislar este test de un bug distinto y preexistente en
        // getPreferences(): el modelo devuelto por ::create() no se
        // refresca, así que age_min/age_max quedan null en PHP en la
        // primera llamada de un usuario nuevo aunque la BD tenga
        // defaults (18/55), rompiendo el filtro de edad.
        \App\Models\UserMatchingPreference::create([
            'user_id' => $this->user->id,
            'age_min' => 18,
            'age_max' => 55,
            'max_distance_km' => 50,
        ]);

        ['user' => $candidate1, 'profile' => $profile1] = createUserWithProfile([
            'display_name' => 'Candidata Uno',
        ]);
        ['user' => $candidate2, 'profile' => $profile2] = createUserWithProfile([
            'display_name' => 'Candidata Dos',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $data = collect($response->json('data'));

        expect($data)->toHaveCount(2);

        $ids = $data->pluck('id');
        expect($ids)->toContain((string) $candidate1->id, (string) $candidate2->id)
            ->and($ids)->not->toContain((string) $profile1->id, (string) $profile2->id);

        $names = $data->pluck('display_name');
        expect($names)->toContain('Candidata Uno', 'Candidata Dos');
    });

    it('usuario nuevo sin preferencias previas obtiene explore no vacío', function () {
        // Regresión del bug descrito arriba: getPreferences() creaba la fila
        // con ::create() sin refrescarla, dejando age_min/age_max en null en
        // PHP (aunque la BD sí aplicara los defaults 18/55 de la migración).
        // subYears(null) rompía el rango de fechas en getExploreQueue() y
        // dejaba el explore vacío, en silencio, en la primera llamada de todo
        // usuario nuevo. A diferencia del test anterior, aquí NO se crean las
        // preferencias explícitamente: se deja que getPreferences() las cree
        // de forma perezosa, que es justo el camino que disparaba el bug.
        ['user' => $candidate] = createUserWithProfile([
            'display_name' => 'Candidata Nueva',
        ]);

        expect(\App\Models\UserMatchingPreference::where('user_id', $this->user->id)->exists())->toBeFalse();

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $data = collect($response->json('data'));

        expect($data)->not->toBeEmpty();
        expect($data->pluck('display_name'))->toContain('Candidata Nueva');
    });

    it('excluye al usuario actual de los resultados', function () {
        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($this->user->id);
    });

    it('excluye usuarios ya swipeados', function () {
        ['user' => $swiped] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $swiped->id,
            'direction' => 'like',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($swiped->id);
    });

    it('excluye usuarios suspendidos y baneados', function () {
        ['user' => $suspended] = createUserWithProfile([], ['status' => 'suspended']);
        ['user' => $banned]    = createUserWithProfile([], ['status' => 'banned']);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($suspended->id);
        expect($ids)->not->toContain($banned->id);
    });

    it('aplica filtro de edad correctamente', function () {
        $young = User::factory()->withCompletedOnboarding()->create([
            'date_of_birth' => now()->subYears(20)->format('Y-m-d'),
        ]);
        Profile::create([
            'user_id' => $young->id, 'display_name' => 'Joven',
            'intention' => 'friendship', 'onboarding_step' => 6, 'onboarding_completed' => true,
        ]);

        $old = User::factory()->withCompletedOnboarding()->create([
            'date_of_birth' => now()->subYears(45)->format('Y-m-d'),
        ]);
        Profile::create([
            'user_id' => $old->id, 'display_name' => 'Mayor',
            'intention' => 'friendship', 'onboarding_step' => 6, 'onboarding_completed' => true,
        ]);

        // Set preferences to age 25-35
        $this->withToken($this->token)
            ->putJson('/api/matching/preferences', ['age_min' => 25, 'age_max' => 35]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($young->id);
        expect($ids)->not->toContain($old->id);
    });

    it('excluye usuarios sin onboarding completo', function () {
        $incomplete = User::factory()->create(['onboarding_completed' => false]);
        Profile::create([
            'user_id' => $incomplete->id, 'display_name' => 'Sin onboarding',
            'intention' => 'friendship', 'onboarding_step' => 2, 'onboarding_completed' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain($incomplete->id);
    });

    it('usuario con intención partner no ve perfiles con intención friendship, community o mentorship', function () {
        ['user' => $partnerUser, 'token' => $partnerToken] = createUserWithProfile([
            'intention' => 'partner',
            'verification_status' => 'verified',
        ]);
        ['user' => $partnerCandidate] = createUserWithProfile(['intention' => 'partner']);
        ['user' => $friendshipCandidate] = createUserWithProfile(['intention' => 'friendship']);
        ['user' => $communityCandidate] = createUserWithProfile(['intention' => 'community']);
        ['user' => $mentorshipCandidate] = createUserWithProfile(['intention' => 'mentorship']);

        $response = $this->withToken($partnerToken)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain((string) $partnerCandidate->id);
        expect($ids)->not->toContain(
            (string) $friendshipCandidate->id,
            (string) $communityCandidate->id,
            (string) $mentorshipCandidate->id
        );
    });

    it('usuario con intención friendship ve perfiles con intención community y mentorship pero no partner', function () {
        ['user' => $friendshipUser, 'token' => $friendshipToken] = createUserWithProfile([
            'intention' => 'friendship',
            'verification_status' => 'verified',
        ]);
        ['user' => $communityCandidate] = createUserWithProfile(['intention' => 'community']);
        ['user' => $mentorshipCandidate] = createUserWithProfile(['intention' => 'mentorship']);
        ['user' => $partnerCandidate] = createUserWithProfile(['intention' => 'partner']);

        $response = $this->withToken($friendshipToken)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain((string) $communityCandidate->id, (string) $mentorshipCandidate->id);
        expect($ids)->not->toContain((string) $partnerCandidate->id);
    });

});

// ---------------------------------------------------------------------------
// POST /api/matching/swipe
// ---------------------------------------------------------------------------

describe('swipe', function () {

    it('registra un like sin crear match cuando no hay like inverso', function () {
        ['user' => $target] = createUserWithProfile();

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.swiped', true)
            ->assertJsonPath('data.matched', false)
            ->assertJsonPath('data.match_id', null);

        $this->assertDatabaseHas('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
            'direction' => 'like',
        ]);
    });

    it('crea match cuando hay like mutuo', function () {
        ['user' => $target] = createUserWithProfile();

        // Target already liked viewer
        Swipe::create([
            'swiper_id' => $target->id,
            'swiped_id' => $this->user->id,
            'direction' => 'like',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.swiped', true)
            ->assertJsonPath('data.matched', true);

        expect($response->json('data.match_id'))->not->toBeNull();

        $this->assertDatabaseCount('matches', 1);
    });

    it('no crea match con dislike aunque exista like inverso', function () {
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $target->id,
            'swiped_id' => $this->user->id,
            'direction' => 'like',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'dislike',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.matched', false);

        $this->assertDatabaseCount('matches', 0);
    });

    it('rechaza swipe duplicado al mismo usuario', function () {
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
            'direction' => 'dislike',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(500); // unique constraint violation
    });

    it('rechaza swipear al propio usuario', function () {
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $this->user->id,
                'direction' => 'like',
            ])
            ->assertStatus(422);
    });

    it('crea match con super_like mutuo', function () {
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $target->id,
            'swiped_id' => $this->user->id,
            'direction' => 'super_like',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.matched', true);
    });

    it('crea conversación automáticamente al hacer match', function () {
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $target->id,
            'swiped_id' => $this->user->id,
            'direction' => 'like',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.matched', true);

        $matchId = $response->json('data.match_id');

        [$id1, $id2] = $this->user->id < $target->id
            ? [$this->user->id, $target->id]
            : [$target->id, $this->user->id];

        $this->assertDatabaseHas('conversations', [
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
            'match_id' => $matchId,
        ]);
    });

});

// ---------------------------------------------------------------------------
// MatchingService::calculateScore
// ---------------------------------------------------------------------------

describe('calculateScore', function () {

    it('da puntos correctos por intereses en común', function () {
        $interests = Interest::take(3)->get();

        $viewerProfile = Profile::create([
            'user_id' => $this->user->id, 'display_name' => 'A',
            'intention' => 'friendship', 'onboarding_step' => 6, 'onboarding_completed' => true,
        ]);
        $viewerProfile->interests()->attach($interests->pluck('id'));

        ['user' => $targetUser] = createUserWithProfile(['intention' => 'friendship']);
        $targetProfile = $targetUser->profile()->first();
        $targetProfile->interests()->attach($interests->take(2)->pluck('id'));

        $viewerProfile->load('interests');
        $targetProfile->load('interests');

        $service = app(MatchingService::class);
        $score = $service->calculateScore($viewerProfile, $targetProfile, false, 50);

        // 2 intereses × 10 + intención coincide 20 = 40
        expect($score)->toBe(40);
    });

    it('suma puntos por perfil verificado', function () {
        $viewerProfile = $this->profile;
        $viewerProfile->load('interests');

        ['user' => $targetUser] = createUserWithProfile(['verification_status' => 'verified', 'intention' => null]);
        $targetProfile = $targetUser->profile()->first();
        $targetProfile->load('interests');

        $service = app(MatchingService::class);
        $score = $service->calculateScore($viewerProfile, $targetProfile, false, 50);

        expect($score)->toBeGreaterThanOrEqual(5);
    });

    it('suma puntos por super_like previo del target', function () {
        $viewerProfile = $this->profile;
        $viewerProfile->load('interests');

        ['user' => $targetUser] = createUserWithProfile(['intention' => null]);
        $targetProfile = $targetUser->profile()->first();
        $targetProfile->load('interests');

        $service = app(MatchingService::class);
        $withSuperLike    = $service->calculateScore($viewerProfile, $targetProfile, true, 50);
        $withoutSuperLike = $service->calculateScore($viewerProfile, $targetProfile, false, 50);

        expect($withSuperLike - $withoutSuperLike)->toBe(15);
    });

});

// ---------------------------------------------------------------------------
// GET /api/matching/preferences
// ---------------------------------------------------------------------------

describe('preferences', function () {

    it('retorna preferencias por defecto si no existen', function () {
        $this->withToken($this->token)
            ->getJson('/api/matching/preferences')
            ->assertStatus(200)
            ->assertJsonPath('data.age_min', 18)
            ->assertJsonPath('data.age_max', 55)
            ->assertJsonPath('data.max_distance_km', 50)
            ->assertJsonPath('data.verified_only', false);
    });

    it('actualiza preferencias correctamente', function () {
        $this->withToken($this->token)
            ->putJson('/api/matching/preferences', [
                'age_min'         => 22,
                'age_max'         => 35,
                'max_distance_km' => 20,
                'verified_only'   => true,
                'intentions'      => ['partner', 'friendship'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.age_min', 22)
            ->assertJsonPath('data.age_max', 35)
            ->assertJsonPath('data.verified_only', true);
    });

});

// ---------------------------------------------------------------------------
// GET /api/matching/matches
// ---------------------------------------------------------------------------

describe('matches list', function () {

    it('retorna lista de matches del usuario', function () {
        ['user' => $other] = createUserWithProfile();

        [$id1, $id2] = $this->user->id < $other->id
            ? [$this->user->id, $other->id]
            : [$other->id, $this->user->id];

        UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/matches')
            ->assertStatus(200);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.other_user'))->not->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// Gate de verificación (backend) — ver spec.md → "Gate de verificación (backend)"
// ---------------------------------------------------------------------------

describe('gate de verificación', function () {

    it('usuario no verificado que llama explore recibe 403', function () {
        ['token' => $unverifiedToken] = createUserWithProfile([
            'verification_status' => 'unverified',
        ]);

        $this->withToken($unverifiedToken)
            ->getJson('/api/matching/explore')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Solo los perfiles verificados pueden explorar y dar like.');
    });

    it('usuario no verificado que llama swipe recibe 403', function () {
        ['token' => $unverifiedToken] = createUserWithProfile([
            'verification_status' => 'unverified',
        ]);
        ['user' => $target] = createUserWithProfile();

        $this->withToken($unverifiedToken)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Solo los perfiles verificados pueden explorar y dar like.');
    });

});
