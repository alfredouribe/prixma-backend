<?php

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

    ['user' => $this->user, 'profile' => $this->profile, 'token' => $this->token] = createUserWithProfile();
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

        ['user' => $targetUser] = createUserWithProfile(['is_verified' => true, 'intention' => null]);
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
