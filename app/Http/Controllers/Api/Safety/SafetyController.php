<?php

namespace App\Http\Controllers\Api\Safety;

use App\Http\Controllers\Controller;
use App\Http\Requests\Safety\BlockRequest;
use App\Http\Requests\Safety\GeoBlockRequest;
use App\Http\Requests\Safety\ReportRequest;
use App\Http\Resources\BlockResource;
use App\Http\Resources\GeoBlockResource;
use App\Services\SafetyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SafetyController extends Controller
{
    public function __construct(private readonly SafetyService $safetyService) {}

    public function storeReport(ReportRequest $request): JsonResponse
    {
        $this->safetyService->reportUser($request->user(), $request->validated());

        return response()->json([
            'message' => 'Reporte enviado. Gracias por ayudar a mantener Prixma segure.',
        ], 201);
    }

    public function storeBlock(BlockRequest $request): JsonResponse
    {
        $block = $this->safetyService->blockUser($request->user(), $request->input('blocked_id'));

        return response()->json([
            'data'    => new BlockResource($block->load('blocked.profile.photos')),
            'message' => 'Usuario bloqueado.',
        ], 201);
    }

    public function indexBlocks(Request $request): AnonymousResourceCollection
    {
        return BlockResource::collection($this->safetyService->getBlocks($request->user()));
    }

    public function destroyBlock(Request $request, string $uuid): JsonResponse
    {
        $this->safetyService->unblockUser($request->user(), $uuid);

        return response()->json(null, 204);
    }

    public function indexGeoBlocks(Request $request): AnonymousResourceCollection
    {
        return GeoBlockResource::collection($this->safetyService->getGeoBlocks($request->user()));
    }

    public function storeGeoBlock(GeoBlockRequest $request): JsonResponse
    {
        $geoBlock = $this->safetyService->createGeoBlock($request->user(), $request->validated());

        return response()->json([
            'data'    => new GeoBlockResource($geoBlock),
            'message' => 'Zona de bloqueo creada.',
        ], 201);
    }

    public function destroyGeoBlock(Request $request, string $uuid): JsonResponse
    {
        $this->safetyService->deleteGeoBlock($request->user(), $uuid);

        return response()->json(null, 204);
    }
}
