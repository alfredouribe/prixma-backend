<?php

namespace App\Services;

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
    public function getExploreQueue(User $user, int $limit = 25): Collection
    {
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
            ->where('users.status', 'active')
            ->where('users.onboarding_completed', true)
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->whereNotNull('profiles.display_name')
            ->select('users.id', 'users.date_of_birth', 'profiles.*')
            ->with([
                'profile.photos',
                'profile.genderIdentities',
                'profile.orientations',
                'profile.pronouns',
                'profile.interests',
            ]);

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
        return DB::transaction(function () use ($user, $swipedId, $direction) {
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

            return ['swiped' => true, 'matched' => true, 'match_id' => $match->id];
        });
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
        return $user->matchingPreferences ?? UserMatchingPreference::create([
            'user_id' => $user->id,
        ]);
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
