<?php

namespace App\Http\Controllers\Api\Feedback;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\SubmitFeedbackRequest;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedbackService) {}

    public function store(SubmitFeedbackRequest $request): JsonResponse
    {
        $this->feedbackService->submit($request->user(), $request->validated());

        return response()->json([
            'data' => null,
            'message' => 'Gracias, recibimos tu mensaje.',
        ], 201);
    }
}
