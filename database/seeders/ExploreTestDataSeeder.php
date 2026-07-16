<?php

namespace Database\Seeders;

use App\Models\GenderIdentity;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\Pronoun;
use App\Models\SexualOrientation;
use App\Models\Swipe;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder de datos de prueba para probar manualmente el flujo de
 * swipe/match de Explorar contra un usuario de prueba real.
 *
 * NO se registra en DatabaseSeeder::run() a propósito — se ejecuta
 * manualmente con:
 *
 *   php artisan db:seed --class=ExploreTestDataSeeder
 *
 * Crea ~10 perfiles verificados con onboarding completo que aparecen
 * en la cola de explorar del usuario de prueba indicado en
 * TEST_USER_ID. De esos, un subconjunto ya tiene un swipe 'like'
 * registrado hacia el usuario de prueba, de modo que cuando el
 * humano les dé like desde la app, MatchingService::recordSwipe()
 * detecta el swipe inverso y crea el match a través del flujo real
 * (no se insertan matches directamente).
 */
class ExploreTestDataSeeder extends Seeder
{
    /**
     * Usuario de prueba ya existente, registrado a mano por el humano vía la app.
     */
    private const TEST_USER_ID = '17800922-d46c-45a0-9ea8-395339ad2dee';

    /**
     * Cuántos de los perfiles seed ya tienen un like previo hacia el
     * usuario de prueba (para probar el flujo de match inmediato).
     */
    private const PROFILES_WITH_PRIOR_LIKE = 5;

    private const TOTAL_PROFILES = 10;

    public function run(): void
    {
        $testUser = User::find(self::TEST_USER_ID);

        if (!$testUser) {
            $this->command?->error(
                'No se encontró el usuario de prueba con id ' . self::TEST_USER_ID .
                '. Registra ese usuario en la app antes de correr este seeder.'
            );
            return;
        }

        // Los catálogos son idempotentes (insertOrIgnore) — se aseguran de existir
        // sin depender de que DatabaseSeeder ya haya corrido.
        $this->call([
            GenderIdentitySeeder::class,
            OrientationSeeder::class,
            PronounSeeder::class,
            InterestSeeder::class,
        ]);

        $genderIdentities = GenderIdentity::all();
        $orientations = SexualOrientation::all();
        $pronouns = Pronoun::all();
        $interests = Interest::all();

        $intentions = ['partner', 'friendship', 'community', 'mentorship'];

        // Ciudad del usuario de prueba (si la tiene) para mantener consistencia;
        // no afecta el filtro de distancia porque el usuario de prueba no tiene
        // latitude/longitude capturadas — MatchingService::calculateScore() solo
        // aplica la penalización/exclusión por distancia si AMBOS perfiles tienen
        // coordenadas.
        $city = $testUser->profile?->city ?? 'CDMX';

        $createdCount = 0;

        for ($i = 1; $i <= self::TOTAL_PROFILES; $i++) {
            $user = User::factory()
                ->withCompletedOnboarding()
                ->create([
                    'date_of_birth' => fake()->dateTimeBetween('-55 years', '-18 years')->format('Y-m-d'),
                ]);

            $profile = Profile::factory()
                ->verified()
                ->create([
                    'user_id'   => $user->id,
                    'display_name' => fake()->firstName(),
                    'bio'       => fake()->sentence(12),
                    'city'      => $city,
                    'intention' => $intentions[array_rand($intentions)],
                ]);

            // Identidad de género — al menos 1
            $profile->genderIdentities()->attach(
                $genderIdentities->random(1)->pluck('id')
            );

            // Orientación — 1 o 2
            $profile->orientations()->attach(
                $orientations->random(random_int(1, 2))->pluck('id')
            );

            // Pronombres — 1
            $profile->pronouns()->attach(
                $pronouns->random(1)->pluck('id')
            );

            // Intereses — mínimo 3 (regla de domain.md)
            $profile->interests()->attach(
                $interests->random(random_int(3, 5))->pluck('id')
            );

            // Al menos una foto para que la card se vea completa
            $photoCount = random_int(1, 3);
            for ($p = 0; $p < $photoCount; $p++) {
                ProfilePhoto::create([
                    'profile_id' => $profile->id,
                    'url'        => "https://i.pravatar.cc/600?img=" . random_int(1, 70),
                    'key'        => "seed/explore-test-data/{$profile->id}/{$p}.jpg",
                    'position'   => $p,
                ]);
            }

            // Subconjunto con like previo hacia el usuario de prueba, para
            // que el match ocurra en cuanto el humano le dé like desde la app.
            if ($i <= self::PROFILES_WITH_PRIOR_LIKE) {
                Swipe::create([
                    'swiper_id' => $user->id,
                    'swiped_id' => $testUser->id,
                    'direction' => 'like',
                ]);
            }

            $createdCount++;
        }

        $this->command?->info(
            "ExploreTestDataSeeder: {$createdCount} perfiles verificados creados. " .
            self::PROFILES_WITH_PRIOR_LIKE . ' de ellos ya dieron like al usuario de prueba (' .
            $testUser->email . '); dales like desde la app para generar el match.'
        );
    }
}
