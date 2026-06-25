<?php

namespace App\Http\Controllers\Api\Matching;

use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\SwipeRequest;
use App\Http\Requests\Matching\UpdateMatchingPreferencesRequest;
use App\Http\Resources\ExploreProfileResource;
use App\Http\Resources\MatchResource;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchingController extends Controller
{
    public function __construct(private readonly MatchingService $matchingService)
    {
    }

    public function explore(Request $request): AnonymousResourceCollection
    {
        $limit = min((int) $request->query('limit', 25), 50);
        $profiles = $this->matchingService->getExploreQueue($request->user(), $limit);

        return ExploreProfileResource::collection($profiles);
    }

    public function swipe(SwipeRequest $request): JsonResponse
    {
        $result = $this->matchingService->recordSwipe(
            $request->user(),
            $request->input('swiped_id'),
            $request->input('direction')
        );

        return response()->json(['data' => $result]);
    }

    public function matches(Request $request): AnonymousResourceCollection
    {
        $matches = $this->matchingService->getMatches($request->user());

        return MatchResource::collection($matches);
    }

    public function getPreferences(Request $request): JsonResponse
    {
        $prefs = $this->matchingService->getPreferences($request->user());

        return response()->json(['data' => $prefs]);
    }

    public function updatePreferences(UpdateMatchingPreferencesRequest $request): JsonResponse
    {
        $prefs = $this->matchingService->updatePreferences(
            $request->user(),
            $request->validated()
        );

        return response()->json(['data' => $prefs]);
    }
}
