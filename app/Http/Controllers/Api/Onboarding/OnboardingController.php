<?php

namespace App\Http\Controllers\Api\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StepIdentityRequest;
use App\Http\Requests\Onboarding\StepInterestsRequest;
use App\Http\Requests\Onboarding\StepIntentionRequest;
use App\Http\Requests\Onboarding\StepPronounsRequest;
use App\Http\Requests\Onboarding\StepSafetyRequest;
use App\Http\Requests\Onboarding\StepVideoRequest;
use App\Http\Resources\OnboardingStatusResource;
use App\Http\Resources\ProfileResource;
use App\Models\GenderIdentity;
use App\Models\Interest;
use App\Models\Pronoun;
use App\Models\SexualOrientation;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboardingService) {}

    public function catalogs(): JsonResponse
    {
        $formatItem = fn($m) => ['id' => $m->id, 'slug' => $m->slug, 'label' => $m->label];

        $interests = Interest::all()->groupBy('category')->map(
            fn($items) => $items->map(fn($i) => [
                'id'       => $i->id,
                'slug'     => $i->slug,
                'label'    => $i->label,
                'category' => $i->category,
            ])->values()
        );

        return response()->json([
            'data' => [
                'gender_identities' => GenderIdentity::all()->map($formatItem)->values(),
                'orientations'      => SexualOrientation::all()->map($formatItem)->values(),
                'pronouns'          => Pronoun::all()->map($formatItem)->values(),
                'interests'         => $interests,
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $status = $this->onboardingService->getStatus($request->user());

        return response()->json([
            'data' => new OnboardingStatusResource($status),
        ]);
    }

    public function stepIdentity(StepIdentityRequest $request): JsonResponse
    {
        $profile = $this->onboardingService->saveIdentity(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Identidad guardada.',
        ]);
    }

    public function stepPronouns(StepPronounsRequest $request): JsonResponse
    {
        $profile = $this->onboardingService->savePronouns(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Pronombres guardados.',
        ]);
    }

    public function stepIntention(StepIntentionRequest $request): JsonResponse
    {
        $profile = $this->onboardingService->saveIntention(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Intención guardada.',
        ]);
    }

    public function stepInterests(StepInterestsRequest $request): JsonResponse
    {
        $profile = $this->onboardingService->saveInterests(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Intereses guardados.',
        ]);
    }

    public function stepVideo(StepVideoRequest $request): JsonResponse
    {
        $profile = $this->onboardingService->saveVideo(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Video recibido. Será procesado en breve.',
        ]);
    }

    public function stepSafety(StepSafetyRequest $request): JsonResponse
    {
        $this->onboardingService->saveSafety(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => '¡Onboarding completado! Bienvenide a Prixma.',
        ]);
    }

    public function videoPresignedUrl(Request $request): JsonResponse
    {
        $result = $this->onboardingService->generateVideoPresignedUrl($request->user());

        return response()->json(['data' => $result]);
    }
}
