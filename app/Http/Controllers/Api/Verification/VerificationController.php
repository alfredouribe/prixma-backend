<?php

namespace App\Http\Controllers\Api\Verification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Verification\SubmitVerificationRequest;
use App\Http\Requests\Verification\VerificationPresignedUrlRequest;
use App\Http\Resources\VerificationStatusResource;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verificationService) {}

    public function presignedUrl(VerificationPresignedUrlRequest $request): JsonResponse
    {
        $result = $this->verificationService->generatePresignedUrl(
            $request->user(),
            $request->validated('type', 'document'),
        );

        return response()->json(['data' => $result]);
    }

    public function submit(SubmitVerificationRequest $request): JsonResponse
    {
        $verificationRequest = $this->verificationService->submit(
            $request->user(),
            $request->validated('document_s3_key'),
            $request->validated('selfie_s3_key'),
        );

        return response()->json([
            'data'    => new VerificationStatusResource($verificationRequest),
            'message' => 'Documento enviado. Tu solicitud está en revisión.',
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $verificationRequest = $this->verificationService->getStatus($request->user());

        return response()->json([
            'data' => $verificationRequest ? new VerificationStatusResource($verificationRequest) : null,
        ]);
    }
}
