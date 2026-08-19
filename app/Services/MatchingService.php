<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Exceptions\LikeLimitExceededException;
use App\Models\Conversation;
use App\Models\PlatformSetting;
use App\Models\Profile;
use App\Models\UserMatch;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatchingPreference;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MatchingService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function getExploreQueue(User $user, int $limit = 25): Collection
    {
        if ($user->profile->verification_status !== 'verified') {
            throw new AuthorizationException('Solo los perfiles verificados pueden explorar y dar like.');
        }

        $prefs = $this->getPreferences($user);

        $alreadySwiped = Swipe::where('swiper_id', $user->id)
            ->pluck('swiped_id');

        // Users who gave this user a super_like (for scoring boost)
        $superLikedMe = Swipe::where('swiped_id', $user->id)
            ->where('direction', 'super_like')
            ->pluck('swiper_id')
            ->flip();

        $query = User::query()
            ->where('users.id', '!=', $user->id)
            ->whereNotIn('users.id', $alreadySwiped)
            ->whereNotIn('users.id', $user->blockedUserIds())
            ->whereNotIn('users.id', $user->blockedByUserIds())
            // Modo incógnito (features/safety/specs/spec.md → "Modo incógnito"):
            // quien lo activa deja de aparecer en la cola de cualquier otro
            // usuario. No afecta lo que el propio usuario en incógnito ve —
            // solo cambia cómo lo ven los demás.
            ->whereDoesntHave('settings', fn ($q) => $q->where('incognito_mode_enabled', true))
            ->where('users.status', 'active')
            ->where('users.onboarding_completed', true)
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->whereNotNull('profiles.display_name')
            ->select('users.id', 'users.date_of_birth')
            ->with([
                'profile.photos',
                'profile.genderIdentities',
                'profile.orientations',
                'profile.pronouns',
                'profile.interests',
            ]);

        // Separación de intenciones — exclusión dura, no depende de
        // user_matching_preferences y se aplica antes que los filtros
        // de preferencias (ver spec.md → "Separación de intenciones").
        $userIntention = $user->profile->intention;

        if ($userIntention === 'partner') {
            $query->where('profiles.intention', 'partner');
        } else {
            $query->whereIn('profiles.intention', ['friendship', 'community', 'mentorship']);
        }

        // Age filter
        $minDob = Carbon::now()->subYears($prefs->age_max)->startOfDay();
        $maxDob = Carbon::now()->subYears($prefs->age_min)->endOfDay();
        $query->whereBetween('users.date_of_birth', [$minDob, $maxDob]);

        // Intention filter
        if (!empty($prefs->intentions)) {
            $query->whereIn('profiles.intention', $prefs->intentions);
        }

        // Verified only filter
        if ($prefs->verified_only) {
            $query->where('profiles.verification_status', 'verified');
        }

        // Video only filter
        if ($prefs->has_video_only) {
            $query->whereNotNull('profiles.video_url')
                ->where('profiles.video_processed', true);
        }

        // Gender identity filter
        if (!empty($prefs->gender_identities)) {
            $query->whereHas('profile.genderIdentities', function ($q) use ($prefs) {
                $q->whereIn('gender_identities.slug', $prefs->gender_identities);
            });
        }

        // Orientation filter
        if (!empty($prefs->orientations)) {
            $query->whereHas('profile.orientations', function ($q) use ($prefs) {
                $q->whereIn('sexual_orientations.slug', $prefs->orientations);
            });
        }

        $candidates = $query->get();

        $viewerProfile = $user->profile()->with('interests')->first();

        return $candidates
            ->map(function ($candidate) use ($viewerProfile, $superLikedMe, $prefs) {
                $profile = $candidate->profile;
                $score = $this->calculateScore(
                    $viewerProfile,
                    $profile,
                    isset($superLikedMe[$candidate->id]),
                    $prefs->max_distance_km
                );
                $candidate->_score = $score;
                return $candidate;
            })
            ->filter(fn($c) => $c->_score >= 0)
            ->sortByDesc('_score')
            ->take($limit)
            ->values();
    }

    public function recordSwipe(User $user, string $swipedId, string $direction): array
    {
        if ($user->profile->verification_status !== 'verified') {
            throw new AuthorizationException('Solo los perfiles verificados pueden explorar y dar like.');
        }

        // Límite diario de likes/super_likes para usuarios sin Prixma+ — ver
        // features/premium/specs/spec.md → "Paywall al agotar likes del
        // día". `dislike` nunca cuenta ni se limita. Se valida aquí (server
        // side) porque, a diferencia de la frecuencia de ads, sí es una
        // regla de negocio con valor económico real.
        // Saldo de super likes extra (features/premium/specs/spec.md →
        // "Super likes extra") — solo aplica a la dirección `super_like`,
        // nunca a `like`. La decisión se toma aquí (lectura) pero el
        // decremento ocurre dentro de la transacción de abajo, junto con la
        // creación del Swipe, para que el crédito nunca se pierda si la
        // creación fallara por cualquier razón.
        $usesExtraSuperLike = false;

        if (!$user->hasPremiumAccess() && in_array($direction, ['like', 'super_like'], true)) {
            $todayLikes = Swipe::where('swiper_id', $user->id)
                ->whereIn('direction', ['like', 'super_like'])
                ->whereDate('created_at', now()->toDateString())
                ->count();

            if ($todayLikes >= PlatformSetting::current()->free_likes_per_day) {
                if ($direction === 'super_like' && $user->extra_super_likes > 0) {
                    $usesExtraSuperLike = true;
                } else {
                    throw new LikeLimitExceededException('Alcanzaste tu límite diario de likes. Actualiza a Prixma+ para dar likes ilimitados.');
                }
            }
        }

        $result = DB::transaction(function () use ($user, $swipedId, $direction, $usesExtraSuperLike) {
            if ($usesExtraSuperLike) {
                $user->decrement('extra_super_likes');
            }

            $swipe = Swipe::create([
                'swiper_id' => $user->id,
                'swiped_id' => $swipedId,
                'direction' => $direction,
            ]);

            if ($direction === 'dislike') {
                return ['swiped' => true, 'matched' => false, 'match_id' => null];
            }

            // Check for mutual like/super_like
            $inverseSwipe = Swipe::where('swiper_id', $swipedId)
                ->where('swiped_id', $user->id)
                ->whereIn('direction', ['like', 'super_like'])
                ->first();

            if (!$inverseSwipe) {
                return ['swiped' => true, 'matched' => false, 'match_id' => null];
            }

            // Ensure consistent ordering to satisfy unique constraint
            [$id1, $id2] = $user->id < $swipedId
                ? [$user->id, $swipedId]
                : [$swipedId, $user->id];

            $match = UserMatch::create([
                'user_id_1' => $id1,
                'user_id_2' => $id2,
            ]);

            $conversation = Conversation::create([
                'user_id_1' => $id1,
                'user_id_2' => $id2,
                'type' => 'match',
                'status' => 'active',
                'match_id' => $match->id,
            ]);

            return [
                'swiped' => true,
                'matched' => true,
                'match_id' => $match->id,
                'conversation_id' => $conversation->id,
            ];
        });

        // Notificaciones fuera de la transacción — un fallo en el envío
        // (best-effort, no debería lanzar, pero por disciplina) nunca debe
        // revertir un swipe/match ya confirmado en BD. Un solo swipe puede
        // disparar las dos a la vez (super_like que además genera match) —
        // domain.md → Swipe: "super_like notifica al swiped aunque no haya
        // match", independiente de la notificación de match.
        $notifyUser = ($direction === 'super_like' || ($result['matched'] ?? false))
            ? User::find($swipedId)
            : null;

        if ($direction === 'super_like' && $notifyUser) {
            $this->notifications->sendSuperLikeNotification($notifyUser);
        }

        if (($result['matched'] ?? false) && $notifyUser) {
            $this->notifications->sendMatchNotification($user, $notifyUser, $result['conversation_id']);
        }

        return $result;
    }

    /**
     * Deshace el último swipe del usuario, consumiendo 1 rewind_credit. Ver
     * features/premium/specs/spec.md/plan.md → "Deshacer swipe / rewind".
     * No hay excepción dedicada (a diferencia de LikeLimitExceededException
     * → 429) — los 3 casos de rechazo son igual de "esperados" y no
     * necesitan una rama de UI especial, BusinessException genérica → 400
     * basta.
     */
    public function rewindLastSwipe(User $user): array
    {
        if ($user->rewind_credits <= 0) {
            throw new BusinessException('No te quedan usos de deshacer swipe.');
        }

        $lastSwipe = Swipe::where('swiper_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$lastSwipe) {
            throw new BusinessException('No hay ningún swipe para deshacer.');
        }

        // Chequeado en ambas direcciones — el orden de user_id_1/user_id_2 en
        // UserMatch no está garantizado (ver recordSwipe(): se ordena por
        // comparación de UUID, no por quién swipeó primero).
        $alreadyMatched = UserMatch::where(function ($q) use ($user, $lastSwipe) {
                $q->where('user_id_1', $user->id)->where('user_id_2', $lastSwipe->swiped_id);
            })
            ->orWhere(function ($q) use ($user, $lastSwipe) {
                $q->where('user_id_1', $lastSwipe->swiped_id)->where('user_id_2', $user->id);
            })
            ->exists();

        if ($alreadyMatched) {
            throw new BusinessException('No puedes deshacer un swipe que ya generó un match.');
        }

        $swipedId = $lastSwipe->swiped_id;

        DB::transaction(function () use ($user, $lastSwipe) {
            $lastSwipe->delete();
            $user->decrement('rewind_credits');
        });

        return ['swiped_id' => $swipedId, 'rewind_credits' => $user->fresh()->rewind_credits];
    }

    /**
     * Lista de personas que le dieron like/super_like al usuario, sin
     * necesidad de match previo. Ver features/premium/specs/spec.md/plan.md
     * → "Ver quién te dio like".
     */
    public function getLikers(User $user): Collection
    {
        if (!$user->canSeeLikers()) {
            throw new AuthorizationException('Necesitas Prixma+ para ver quién te dio like.');
        }

        $alreadySwipedIds = Swipe::where('swiper_id', $user->id)->pluck('swiped_id');

        $likerIdsInOrder = Swipe::where('swiped_id', $user->id)
            ->whereIn('direction', ['like', 'super_like'])
            ->whereNotIn('swiper_id', $alreadySwipedIds)
            ->orderByDesc('created_at')
            ->pluck('swiper_id')
            ->unique()
            ->values();

        $candidates = User::query()
            ->whereIn('id', $likerIdsInOrder)
            ->whereNotIn('id', $user->blockedUserIds())
            ->whereNotIn('id', $user->blockedByUserIds())
            ->where('status', 'active')
            ->with(['profile.photos', 'profile.genderIdentities', 'profile.orientations', 'profile.pronouns', 'profile.interests'])
            ->get()
            ->keyBy('id');

        // whereIn no garantiza orden — se reconstruye manualmente para
        // preservar "más reciente primero".
        return $likerIdsInOrder->map(fn ($id) => $candidates->get($id))->filter()->values();
    }

    public function calculateScore(
        Profile $viewer,
        Profile $target,
        bool $targetSuperLikedViewer,
        int $maxDistanceKm
    ): int {
        $score = 0;

        // Shared interests
        $viewerInterests = $viewer->interests->pluck('id')->toArray();
        $targetInterests = $target->interests->pluck('id')->toArray();
        $shared = count(array_intersect($viewerInterests, $targetInterests));
        $score += $shared * 10;

        // Matching intention
        if ($viewer->intention && $target->intention && $viewer->intention === $target->intention) {
            $score += 20;
        }

        // Verified profile
        if ($target->verification_status === 'verified') {
            $score += 5;
        }

        // Has video
        if ($target->video_url && $target->video_processed) {
            $score += 5;
        }

        // Target already super-liked the viewer
        if ($targetSuperLikedViewer) {
            $score += 15;
        }

        // Boost de perfil (features/premium/specs/spec.md → "Boost de
        // perfil") — bono dominante, muy por encima de cualquier
        // combinación posible del resto de criterios (máximo real: ~45 sin
        // boost, penalización de distancia hasta -50), garantiza que un
        // perfil boosteado quede primero entre los candidatos que ya
        // pasaron los filtros. Se agrega ANTES de la exclusión por
        // distancia de abajo a propósito — el boost no bypasea ese filtro,
        // solo prioriza entre quienes ya calificaron.
        if ($target->boosted_until !== null && $target->boosted_until->isFuture()) {
            $score += 10000;
        }

        // Distance penalty (only if both have location)
        if ($viewer->latitude && $viewer->longitude && $target->latitude && $target->longitude) {
            $distanceKm = $this->haversineDistanceKm(
                (float) $viewer->latitude,
                (float) $viewer->longitude,
                (float) $target->latitude,
                (float) $target->longitude
            );

            if ($distanceKm > $maxDistanceKm) {
                return -1; // Exclude — outside distance filter
            }

            $penalty = min((int) $distanceKm, 50);
            $score -= $penalty;
        }

        return $score;
    }

    public function getMatches(User $user): Collection
    {
        return UserMatch::where('user_id_1', $user->id)
            ->orWhere('user_id_2', $user->id)
            ->with([
                'user1.profile.photos',
                'user2.profile.photos',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($match) use ($user) {
                $other = $match->user_id_1 === $user->id ? $match->user2 : $match->user1;
                $match->other_user = $other;
                return $match;
            });
    }

    public function getPreferences(User $user): UserMatchingPreference
    {
        $prefs = $user->matchingPreferences ?? UserMatchingPreference::firstOrCreate([
            'user_id' => $user->id,
        ]);

        // `firstOrCreate` inserta solo `user_id` cuando la fila no existía,
        // dejando que MySQL aplique los defaults de columna en el INSERT
        // (age_min: 18, age_max: 55, max_distance_km: 50, etc.). La instancia
        // en memoria, sin embargo, no refleja esos defaults (los atributos
        // nunca se asignaron en PHP) hasta recargarla — mismo patrón que
        // `ProfileService::getSettings()`.
        if ($prefs->wasRecentlyCreated) {
            $prefs = $prefs->fresh();
        }

        return $prefs;
    }

    public function updatePreferences(User $user, array $data): UserMatchingPreference
    {
        $prefs = $this->getPreferences($user);
        $prefs->update($data);
        return $prefs->fresh();
    }

    private function haversineDistanceKm(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
