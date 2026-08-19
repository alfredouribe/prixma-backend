<?php

use App\Jobs\SendMatchNotification;
use App\Jobs\SendSuperLikeNotification;
use App\Models\Conversation;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\PlatformSetting;
use App\Models\UserMatch;
use App\Models\UserSetting;
use App\Models\Swipe;
use App\Models\User;
use App\Services\MatchingService;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;
use Illuminate\Support\Facades\Queue;

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

    it('un candidato con boosted_until vigente aparece primero en la cola real, aunque tenga peor score en todo lo demás', function () {
        // El boosteado no comparte intención con el viewer (community vs.
        // friendship del viewer, así que no suma el bono de +20 por
        // coincidencia) y no está verificado — sin el bono de boost,
        // quedaría por debajo del candidato "normal" de abajo. `intention`
        // no puede ser null: getExploreQueue() excluye del query a quien no
        // tenga una de las intenciones compartidas del viewer (aquí
        // friendship/community/mentorship), antes de llegar al scoring.
        ['profile' => $boostedProfile] = createUserWithProfile([
            'display_name' => 'Perfil Boosteado',
            'intention' => 'community',
            'verification_status' => 'unverified',
        ]);
        $boostedProfile->update(['boosted_until' => now()->addMinutes(30)]);

        createUserWithProfile([
            'display_name' => 'Perfil Normal Mejor Score',
            'intention' => 'friendship', // coincide con el viewer, +20
            'verification_status' => 'verified', // +5
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('display_name');
        expect($names->first())->toBe('Perfil Boosteado');
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

    it('excluye usuarios con modo incógnito activado (features/safety/specs/spec.md)', function () {
        ['user' => $incognito] = createUserWithProfile();
        UserSetting::create(['user_id' => $incognito->id, 'incognito_mode_enabled' => true]);

        ['user' => $visible] = createUserWithProfile();

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain((string) $incognito->id);
        expect($ids)->toContain((string) $visible->id);
    });

    it('el propio modo incógnito no afecta lo que el usuario ve en su explorar', function () {
        UserSetting::create(['user_id' => $this->user->id, 'incognito_mode_enabled' => true]);
        ['user' => $visible] = createUserWithProfile();

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/explore')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain((string) $visible->id);
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

    it('despacha SendMatchNotification al hacer match', function () {
        Queue::fake();
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $target->id,
            'swiped_id' => $this->user->id,
            'direction' => 'like',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200);

        Queue::assertPushed(SendMatchNotification::class);
    });

    it('no despacha SendMatchNotification si el swipe no produjo match', function () {
        Queue::fake();
        ['user' => $target] = createUserWithProfile();

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.matched', false);

        Queue::assertNotPushed(SendMatchNotification::class);
    });

    it('despacha SendSuperLikeNotification cuando direction es super_like', function () {
        Queue::fake();
        ['user' => $target] = createUserWithProfile();

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'super_like',
            ])
            ->assertStatus(200);

        Queue::assertPushed(SendSuperLikeNotification::class);
    });

    it('no despacha SendSuperLikeNotification con un like normal', function () {
        Queue::fake();
        ['user' => $target] = createUserWithProfile();

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', [
                'swiped_id' => $target->id,
                'direction' => 'like',
            ])
            ->assertStatus(200);

        Queue::assertNotPushed(SendSuperLikeNotification::class);
    });

});

// ---------------------------------------------------------------------------
// Límite diario de likes (features/premium/specs/spec.md)
// ---------------------------------------------------------------------------

describe('límite de likes/día', function () {

    it('rechaza el like que excede el límite diario para un usuario no premium', function () {
        $settings = PlatformSetting::current();
        $settings->update(['free_likes_per_day' => 2]);

        for ($i = 0; $i < 2; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'like'])
                ->assertStatus(200);
        }

        ['user' => $thirdTarget] = createUserWithProfile();

        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $thirdTarget->id, 'direction' => 'like'])
            ->assertStatus(429);

        $this->assertDatabaseMissing('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $thirdTarget->id,
        ]);
    });

    it('no rechaza a un usuario premium aunque exceda el límite', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['is_premium' => true]);

        for ($i = 0; $i < 3; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'like'])
                ->assertStatus(200);
        }
    });

    it('un usuario con premium_until futuro (sin is_premium) también puede dar likes ilimitados', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['is_premium' => false, 'premium_until' => now()->addDays(3)]);

        for ($i = 0; $i < 3; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'like'])
                ->assertStatus(200);
        }
    });

    it('un usuario con Subscription activa (sin is_premium, sin premium_until) también puede dar likes ilimitados', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['is_premium' => false, 'premium_until' => null]);
        \App\Models\Subscription::factory()->for($this->user)->create(['status' => 'active']);

        for ($i = 0; $i < 3; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'like'])
                ->assertStatus(200);
        }
    });

    it('dislike nunca cuenta para el límite', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);

        for ($i = 0; $i < 5; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'dislike'])
                ->assertStatus(200);
        }

        ['user' => $likeTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $likeTarget->id, 'direction' => 'like'])
            ->assertStatus(200);
    });

    it('respeta un free_likes_per_day distinto al default', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 3]);

        for ($i = 0; $i < 3; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'like'])
                ->assertStatus(200);
        }

        ['user' => $fourthTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $fourthTarget->id, 'direction' => 'like'])
            ->assertStatus(429);
    });

    it('un like de un día anterior no cuenta para el límite de hoy', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);

        ['user' => $yesterdayTarget] = createUserWithProfile();
        // `Swipe::booted()` fija `created_at = now()` en `creating` sin
        // importar lo que se pase a `create()` — se corrige después con una
        // asignación directa (no pasa por `$fillable`, `save()` no vuelve a
        // disparar el hook de `creating`).
        $yesterdaySwipe = Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $yesterdayTarget->id,
            'direction' => 'like',
        ]);
        $yesterdaySwipe->created_at = now()->subDay();
        $yesterdaySwipe->save();

        ['user' => $todayTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $todayTarget->id, 'direction' => 'like'])
            ->assertStatus(200);
    });

});

// ---------------------------------------------------------------------------
// Super likes extra (features/premium/specs/spec.md → "Super likes extra")
// ---------------------------------------------------------------------------

describe('super likes extra', function () {

    it('un super_like más allá del límite diario se permite y consume 1 crédito si hay saldo', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['extra_super_likes' => 2]);

        ['user' => $firstTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $firstTarget->id, 'direction' => 'like'])
            ->assertStatus(200);

        ['user' => $secondTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $secondTarget->id, 'direction' => 'super_like'])
            ->assertStatus(200);

        expect($this->user->fresh()->extra_super_likes)->toBe(1);
        $this->assertDatabaseHas('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $secondTarget->id,
            'direction' => 'super_like',
        ]);
    });

    it('un like normal (no super_like) más allá del límite NO consume el saldo de super likes extra', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['extra_super_likes' => 2]);

        ['user' => $firstTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $firstTarget->id, 'direction' => 'like'])
            ->assertStatus(200);

        ['user' => $secondTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $secondTarget->id, 'direction' => 'like'])
            ->assertStatus(429);

        expect($this->user->fresh()->extra_super_likes)->toBe(2);
    });

    it('sin saldo de super likes extra, el super_like más allá del límite sigue rechazado con 429', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['extra_super_likes' => 0]);

        ['user' => $firstTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $firstTarget->id, 'direction' => 'like'])
            ->assertStatus(200);

        ['user' => $secondTarget] = createUserWithProfile();
        $this->withToken($this->token)
            ->postJson('/api/matching/swipe', ['swiped_id' => $secondTarget->id, 'direction' => 'super_like'])
            ->assertStatus(429);

        $this->assertDatabaseMissing('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $secondTarget->id,
        ]);
    });

    it('un usuario premium nunca consume el saldo de super likes extra', function () {
        PlatformSetting::current()->update(['free_likes_per_day' => 1]);
        $this->user->update(['is_premium' => true, 'extra_super_likes' => 2]);

        for ($i = 0; $i < 3; $i++) {
            ['user' => $target] = createUserWithProfile();
            $this->withToken($this->token)
                ->postJson('/api/matching/swipe', ['swiped_id' => $target->id, 'direction' => 'super_like'])
                ->assertStatus(200);
        }

        expect($this->user->fresh()->extra_super_likes)->toBe(2);
    });

});

// ---------------------------------------------------------------------------
// POST /api/matching/rewind (features/premium/specs/spec.md → "Deshacer swipe / rewind")
// ---------------------------------------------------------------------------

describe('rewind', function () {

    it('deshace exitosamente el último swipe: borra el Swipe y resta 1 crédito', function () {
        $this->user->update(['rewind_credits' => 3]);
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
            'direction' => 'like',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/rewind')
            ->assertStatus(200)
            ->assertJsonPath('data.swiped_id', (string) $target->id)
            ->assertJsonPath('data.rewind_credits', 2);

        $this->assertDatabaseMissing('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
        ]);
        expect($this->user->fresh()->rewind_credits)->toBe(2);
    });

    it('rechaza con 400 cuando el usuario no tiene créditos de rewind', function () {
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
            'direction' => 'like',
        ]);

        // rewind_credits es 0 por default (no se asignó explícitamente).
        $this->withToken($this->token)
            ->postJson('/api/matching/rewind')
            ->assertStatus(400);

        $this->assertDatabaseHas('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
        ]);
    });

    it('rechaza con 400 cuando no hay ningún swipe para deshacer', function () {
        $this->user->update(['rewind_credits' => 1]);

        $this->withToken($this->token)
            ->postJson('/api/matching/rewind')
            ->assertStatus(400);

        expect($this->user->fresh()->rewind_credits)->toBe(1);
    });

    it('rechaza con 400 cuando el último swipe ya generó un match, sin borrar nada', function () {
        $this->user->update(['rewind_credits' => 1]);
        ['user' => $target] = createUserWithProfile();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
            'direction' => 'like',
        ]);

        [$id1, $id2] = $this->user->id < $target->id
            ? [$this->user->id, $target->id]
            : [$target->id, $this->user->id];

        UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);

        $this->withToken($this->token)
            ->postJson('/api/matching/rewind')
            ->assertStatus(400);

        $this->assertDatabaseHas('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $target->id,
        ]);
        expect($this->user->fresh()->rewind_credits)->toBe(1);
    });

    it('solo deshace el último swipe — uno anterior se queda intacto', function () {
        $this->user->update(['rewind_credits' => 1]);
        ['user' => $olderTarget] = createUserWithProfile();
        ['user' => $newerTarget] = createUserWithProfile();

        $olderSwipe = Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $olderTarget->id,
            'direction' => 'like',
        ]);
        $olderSwipe->created_at = now()->subMinutes(10);
        $olderSwipe->save();

        Swipe::create([
            'swiper_id' => $this->user->id,
            'swiped_id' => $newerTarget->id,
            'direction' => 'dislike',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/matching/rewind')
            ->assertStatus(200)
            ->assertJsonPath('data.swiped_id', (string) $newerTarget->id);

        $this->assertDatabaseMissing('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $newerTarget->id,
        ]);
        $this->assertDatabaseHas('swipes', [
            'swiper_id' => $this->user->id,
            'swiped_id' => $olderTarget->id,
        ]);
    });

    it('requiere autenticación', function () {
        $this->postJson('/api/matching/rewind')->assertStatus(401);
    });

});

// ---------------------------------------------------------------------------
// GET /api/matching/likers (features/premium/specs/spec.md → "Ver quién te
// dio like")
// ---------------------------------------------------------------------------

describe('likers', function () {

    it('retorna la lista de quienes dieron like/super_like, más reciente primero', function () {
        $this->user->update(['is_premium' => true]);

        ['user' => $oldestLiker] = createUserWithProfile(['display_name' => 'Liker Viejo']);
        ['user' => $middleLiker] = createUserWithProfile(['display_name' => 'Liker Medio']);
        ['user' => $newestLiker] = createUserWithProfile(['display_name' => 'Liker Nuevo']);

        // Swipe::booted() fija created_at = now() en creating() sin importar
        // lo que se pase a create() — se corrige después con una asignación
        // directa (no pasa por $fillable, save() no vuelve a disparar el
        // hook de creating), mismo patrón ya usado en la suite de rewind.
        $oldest = Swipe::create(['swiper_id' => $oldestLiker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);
        $oldest->created_at = now()->subMinutes(30);
        $oldest->save();

        $middle = Swipe::create(['swiper_id' => $middleLiker->id, 'swiped_id' => $this->user->id, 'direction' => 'super_like']);
        $middle->created_at = now()->subMinutes(20);
        $middle->save();

        $newest = Swipe::create(['swiper_id' => $newestLiker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);
        $newest->created_at = now()->subMinutes(10);
        $newest->save();

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('display_name');
        expect($names->all())->toBe(['Liker Nuevo', 'Liker Medio', 'Liker Viejo']);
    });

    it('excluye a quien el usuario ya swipeó, en cualquier dirección', function () {
        $this->user->update(['is_premium' => true]);

        ['user' => $likedAlready] = createUserWithProfile();
        ['user' => $dislikedAlready] = createUserWithProfile();
        ['user' => $superLikedAlready] = createUserWithProfile();
        ['user' => $notYetSwiped] = createUserWithProfile();

        foreach ([$likedAlready, $dislikedAlready, $superLikedAlready, $notYetSwiped] as $liker) {
            Swipe::create(['swiper_id' => $liker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);
        }

        Swipe::create(['swiper_id' => $this->user->id, 'swiped_id' => $likedAlready->id, 'direction' => 'like']);
        Swipe::create(['swiper_id' => $this->user->id, 'swiped_id' => $dislikedAlready->id, 'direction' => 'dislike']);
        Swipe::create(['swiper_id' => $this->user->id, 'swiped_id' => $superLikedAlready->id, 'direction' => 'super_like']);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain(
            (string) $likedAlready->id,
            (string) $dislikedAlready->id,
            (string) $superLikedAlready->id
        );
        expect($ids)->toContain((string) $notYetSwiped->id);
    });

    it('excluye usuarios bloqueados en ambas direcciones', function () {
        $this->user->update(['is_premium' => true]);

        ['user' => $blockedByMe] = createUserWithProfile();
        ['user' => $blockedMe] = createUserWithProfile();
        ['user' => $visibleLiker] = createUserWithProfile();

        foreach ([$blockedByMe, $blockedMe, $visibleLiker] as $liker) {
            Swipe::create(['swiper_id' => $liker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);
        }

        \App\Models\Block::create(['blocker_id' => $this->user->id, 'blocked_id' => $blockedByMe->id]);
        \App\Models\Block::create(['blocker_id' => $blockedMe->id, 'blocked_id' => $this->user->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->not->toContain((string) $blockedByMe->id, (string) $blockedMe->id);
        expect($ids)->toContain((string) $visibleLiker->id);
    });

    it('retorna 403 cuando el usuario no tiene acceso', function () {
        $this->user->update(['is_premium' => false, 'premium_until' => null, 'see_likers_until' => null]);

        $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(403);
    });

    it('permite el acceso vía hasPremiumAccess() (is_premium=true)', function () {
        $this->user->update(['is_premium' => true, 'see_likers_until' => null]);
        ['user' => $liker] = createUserWithProfile();
        Swipe::create(['swiper_id' => $liker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(200);

        expect(collect($response->json('data'))->pluck('id'))->toContain((string) $liker->id);
    });

    it('permite el acceso vía see_likers_until vigente, sin is_premium y sin suscripción activa', function () {
        $this->user->update([
            'is_premium' => false,
            'premium_until' => null,
            'see_likers_until' => now()->addDays(3),
        ]);
        ['user' => $liker] = createUserWithProfile();
        Swipe::create(['swiper_id' => $liker->id, 'swiped_id' => $this->user->id, 'direction' => 'like']);

        $response = $this->withToken($this->token)
            ->getJson('/api/matching/likers')
            ->assertStatus(200);

        expect(collect($response->json('data'))->pluck('id'))->toContain((string) $liker->id);
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/matching/likers')->assertStatus(401);
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

    it('un target con boosted_until vigente recibe un bono dominante por encima de cualquier otro criterio', function () {
        $viewerProfile = $this->profile;
        $viewerProfile->load('interests');

        ['user' => $targetUser] = createUserWithProfile(['intention' => null, 'verification_status' => 'unverified']);
        $targetProfile = $targetUser->profile()->first();
        $targetProfile->update(['boosted_until' => now()->addMinutes(30)]);
        $targetProfile->load('interests');

        $service = app(MatchingService::class);
        $score = $service->calculateScore($viewerProfile, $targetProfile, false, 50);

        // Sin ningún otro criterio a favor, el bono de boost (+10000) debe
        // dominar por completo — muy por encima del máximo real posible sin
        // boost (intereses + intención + verificado + video + super_like).
        expect($score)->toBeGreaterThanOrEqual(10000);
    });

    it('boosted_until vencido no otorga ningún bono', function () {
        $viewerProfile = $this->profile;
        $viewerProfile->load('interests');

        ['user' => $targetUser] = createUserWithProfile(['intention' => null, 'verification_status' => 'unverified']);
        $targetProfile = $targetUser->profile()->first();
        $targetProfile->update(['boosted_until' => now()->subMinute()]);
        $targetProfile->load('interests');

        $service = app(MatchingService::class);
        $score = $service->calculateScore($viewerProfile, $targetProfile, false, 50);

        expect($score)->toBeLessThan(100);
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
