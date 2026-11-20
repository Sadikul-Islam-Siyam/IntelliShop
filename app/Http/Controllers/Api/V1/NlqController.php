<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Nlq\AnswerComposer;
use App\Services\Nlq\NlqAuthorizer;
use App\Services\Nlq\WhitelistedQueryRegistry;
use App\Services\NlqIntentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NlqController (Sub-Features #60, #61, #62, #63)
 *
 * Handles Natural-Language Business Queries:
 *  - Intent classification and slot filling (#60)
 *  - Whitelisted parameterised query execution (#61)
 *  - Role permission and branch-scoped authorization (#62)
 *  - Answer composition with evidence citations & audit logging (#63)
 */
class NlqController extends Controller
{
    public function __construct(
        protected NlqIntentService $nlqIntentService,
        protected NlqAuthorizer $nlqAuthorizer,
        protected WhitelistedQueryRegistry $queryRegistry,
        protected AnswerComposer $answerComposer
    ) {}

    /**
     * POST /api/v1/nlq/ask
     * End-to-end NLQ: resolve -> authorize & scope -> execute query -> compose answer + audit log.
     */
    public function ask(Request $request): JsonResponse
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

        $user = $request->user();
        $referenceDate = $request->input('reference_date');

        // 1. Resolve natural-language question into intent & slots
        $resolved = $this->nlqIntentService->resolve($rawQuery, is_string($referenceDate) ? $referenceDate : null);
        $intent = $resolved['intent'] ?? 'unknown';
        $slots = $resolved['slots'] ?? [];
        $confidence = (float) ($resolved['confidence'] ?? 0.0);

        if ($intent === 'unknown' || empty($resolved['is_safe'])) {
            AuditLogger::log(
                $user?->id,
                'nlq.unknown',
                'nlq_queries',
                null,
                [
                    'query' => $rawQuery,
                    'intent' => 'unknown',
                    'message' => $resolved['fallback_message'] ?? NlqIntentService::SAFE_FALLBACK_MESSAGE,
                ],
                $request->ip()
            );

            return response()->json([
                'query' => $rawQuery,
                'intent' => 'unknown',
                'confidence' => 0.0,
                'narrative_answer' => $resolved['fallback_message'] ?? NlqIntentService::SAFE_FALLBACK_MESSAGE,
                'table' => [
                    'columns' => [],
                    'rows' => [],
                    'count' => 0,
                ],
                'sources' => [
                    'primary_source_tables' => 'none',
                    'filter_constraints' => 'none',
                    'query_intent' => 'unknown',
                    'rows_matched' => 0,
                    'query_latency_ms' => 0.0,
                    'audit_verified' => true,
                ],
                'metadata' => [
                    'execution_time_ms' => 0.0,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        }

        // 2. Authorize user against the intent & enforce branch scope (#62)
        try {
            $scopedSlots = $this->nlqAuthorizer->authorizeAndScope($user, $intent, $slots);
        } catch (AuthorizationException $e) {
            AuditLogger::log(
                $user?->id,
                'nlq.forbidden',
                'nlq_queries',
                null,
                [
                    'query' => $rawQuery,
                    'intent' => $intent,
                    'error' => $e->getMessage(),
                ],
                $request->ip()
            );

            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        }

        // 3. Execute whitelisted query (#61)
        $queryResult = $this->queryRegistry->execute($intent, $scopedSlots);

        // 4. Compose complete answer with evidence citations (#63)
        $composed = $this->answerComposer->compose($intent, $scopedSlots, $queryResult, $rawQuery, $confidence);

        // 5. Audit log entry (#63, #5)
        AuditLogger::log(
            $user?->id,
            'nlq.ask',
            'nlq_queries',
            null,
            [
                'query' => $rawQuery,
                'intent' => $intent,
                'slots' => $scopedSlots,
                'row_count' => $queryResult['count'],
                'execution_time_ms' => $queryResult['execution_time_ms'],
            ],
            $request->ip()
        );

        return response()->json($composed);
    }

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
