<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadPhotoRequest;
use App\Http\Resources\ProfilePhotoResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\PublicProfileResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profileService) {}

    public function me(Request $request): JsonResponse
    {
        $result = $this->profileService->getOwnProfile($request->user());

        return response()->json([
            'data' => (new ProfileResource($result['profile']))->withStatistics($result['statistics']),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profileService->updateProfile($request->user(), $request->validated());

        return response()->json([
            'data'    => new ProfileResource($profile),
            'message' => 'Perfil actualizado.',
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $profile = $this->profileService->getPublicProfile($uuid);

        return response()->json([
            'data' => new PublicProfileResource($profile),
        ]);
    }

    public function storePhoto(UploadPhotoRequest $request): JsonResponse
    {
        $photo = $this->profileService->addPhoto($request->user(), $request->file('photo'));

        return response()->json([
            'data'    => new ProfilePhotoResource($photo),
            'message' => 'Foto agregada.',
        ], 201);
    }

    public function destroyPhoto(Request $request, string $uuid): JsonResponse
    {
        $this->profileService->deletePhoto($request->user(), $uuid);

        return response()->json(['message' => 'Foto eliminada.']);
    }

    public function reorderPhotos(Request $request): JsonResponse
    {
        $request->validate([
            'ordered_ids'   => 'required|array|min:1',
            'ordered_ids.*' => 'required|uuid',
        ]);

        $this->profileService->reorderPhotos($request->user(), $request->input('ordered_ids'));

        return response()->json(['message' => 'Orden actualizado.']);
    }

    public function videoPresignedUrl(Request $request): JsonResponse
    {
        $result = $this->profileService->generateVideoPresignedUrl($request->user());

        return response()->json(['data' => $result]);
    }

    public function storeVideo(Request $request): JsonResponse
    {
        $request->validate([
            'video' => 'required|file|max:204800',
        ]);

        $this->profileService->saveVideo($request->user(), $request->file('video'));

        return response()->json(['message' => 'Video recibido. Será procesado en breve.']);
    }

    public function destroyVideo(Request $request): JsonResponse
    {
        $this->profileService->deleteVideo($request->user());

        return response()->json(['message' => 'Video eliminado.']);
    }
}
