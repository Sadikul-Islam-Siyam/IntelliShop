<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\NlqIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NlqController (Sub-Feature #60)
 *
 * Handles Natural-Language Business Query intent classification and slot filling.
 */
class NlqController extends Controller
{
    public function __construct(
        protected NlqIntentService $nlqIntentService
    ) {}

    /**
     * POST /api/v1/nlq/resolve
     * Resolve a natural-language business question into a whitelisted intent and slots.
     */
    public function resolve(Request $request): JsonResponse
    {
        $rawQuery = $request->input('query', $request->input('question'));

        if (!is_string($rawQuery) || trim($rawQuery) === '') {
            return response()->json([
                'message' => 'The query field is required.',
                'errors' => [
                    'query' => ['Please enter a natural-language business question.'],
                ],
            ], 422);
        }

        $referenceDate = $request->input('reference_date');
        $resolved = $this->nlqIntentService->resolve(
            $rawQuery,
            is_string($referenceDate) ? $referenceDate : null
        );

        AuditLogger::log(
            $request->user()?->id,
            'nlq.resolve',
            'nlq',
            null,
            [
                'query' => $resolved['query'],
                'intent' => $resolved['intent'],
                'confidence' => $resolved['confidence'],
                'slots' => $resolved['slots'],
            ],
            $request->ip()
        );

        return response()->json(array_merge([
            'data' => $resolved,
        ], $resolved));
    }
}
