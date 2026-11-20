<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\AiRecommendation;
use Illuminate\Support\Collection;

/**
 * PriorityScorer (Sub-Feature #58)
 *
 * Computes weighted priority scores for AI recommendations:
 *   priority_score = w_risk * risk_score + w_value * value_score + w_urgency * urgency_score
 * where weights (w_risk, w_value, w_urgency) are configurable in system_settings
 * and normalized to sum to 1.0. Recommendations are ordered highest score first.
 */
class PriorityScorer
{
    public const DEFAULT_WEIGHT_RISK = 0.45;
    public const DEFAULT_WEIGHT_VALUE = 0.25;
    public const DEFAULT_WEIGHT_URGENCY = 0.30;
    public const DEFAULT_VALUE_AT_STAKE_CAP = 1000.0;

    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Resolve normalized priority weights from system_settings.
     *
     * @param  array<string, float>|null  $overrideWeights
     * @return array{risk: float, value: float, urgency: float}
     */
    public function resolveWeights(?array $overrideWeights = null): array
    {
        $wRisk = (float) ($overrideWeights['risk']
            ?? $this->settings->get('recommendation_weight_risk', self::DEFAULT_WEIGHT_RISK)
            ?? self::DEFAULT_WEIGHT_RISK);

        $wValue = (float) ($overrideWeights['value']
            ?? $this->settings->get('recommendation_weight_value', self::DEFAULT_WEIGHT_VALUE)
            ?? self::DEFAULT_WEIGHT_VALUE);

        $wUrgency = (float) ($overrideWeights['urgency']
            ?? $this->settings->get('recommendation_weight_urgency', self::DEFAULT_WEIGHT_URGENCY)
            ?? self::DEFAULT_WEIGHT_URGENCY);

        $wRisk = max(0.0, $wRisk);
        $wValue = max(0.0, $wValue);
        $wUrgency = max(0.0, $wUrgency);

        $sum = $wRisk + $wValue + $wUrgency;
        if ($sum <= 0.0) {
            return [
                'risk' => self::DEFAULT_WEIGHT_RISK,
                'value' => self::DEFAULT_WEIGHT_VALUE,
                'urgency' => self::DEFAULT_WEIGHT_URGENCY,
            ];
        }

        return [
            'risk' => round($wRisk / $sum, 4),
            'value' => round($wValue / $sum, 4),
            'urgency' => round($wUrgency / $sum, 4),
        ];
    }

    /**
     * Calculate composite priority score (0.00 to 100.00) and breakdown from input factors.
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, float>|null  $overrideWeights
     * @return array{priority_score: float, priority: string, components: array<string, float>, weights: array<string, float>}
     */
    public function score(array $inputs, ?array $overrideWeights = null): array
    {
        $weights = $this->resolveWeights($overrideWeights);

        $riskScore = $this->normalizeRiskScore($inputs);
        $valueScore = $this->normalizeValueScore($inputs);
        $urgencyScore = $this->normalizeUrgencyScore($inputs);

        $composite = ($weights['risk'] * $riskScore)
            + ($weights['value'] * $valueScore)
            + ($weights['urgency'] * $urgencyScore);

        $priorityScore = round(max(0.0, min(100.0, $composite)), 2);

        $priorityLabel = match (true) {
            $priorityScore >= 75.0 => 'high',
            $priorityScore >= 45.0 => 'medium',
            default => 'low',
        };

        return [
            'priority_score' => $priorityScore,
            'priority' => $priorityLabel,
            'components' => [
                'risk_score' => round($riskScore, 2),
                'value_score' => round($valueScore, 2),
                'urgency_score' => round($urgencyScore, 2),
            ],
            'weights' => $weights,
        ];
    }

    /**
     * Sort a collection or array of recommendations by priority_score descending (highest first).
     *
     * @param  iterable<int, mixed>  $recommendations
     * @return Collection<int, mixed>
     */
    public function rankQueue(iterable $recommendations): Collection
    {
        return collect($recommendations)
            ->sort(function ($a, $b): int {
                $scoreA = (float) (is_array($a) ? ($a['priority_score'] ?? 0.0) : ($a->priority_score ?? 0.0));
                $scoreB = (float) (is_array($b) ? ($b['priority_score'] ?? 0.0) : ($b->priority_score ?? 0.0));

                if (abs($scoreA - $scoreB) > 1e-6) {
                    return $scoreB <=> $scoreA;
                }

                $idA = (int) (is_array($a) ? ($a['id'] ?? 0) : ($a->id ?? 0));
                $idB = (int) (is_array($b) ? ($b['id'] ?? 0) : ($b->id ?? 0));

                return $idA <=> $idB;
            })
            ->values();
    }

    /**
     * Normalize risk level or numeric risk score to a 0..100 scale.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function normalizeRiskScore(array $inputs): float
    {
        if (isset($inputs['risk_score']) && is_numeric($inputs['risk_score'])) {
            $val = (float) $inputs['risk_score'];
            return max(0.0, min(100.0, $val <= 1.0 && $val > 0.0 ? $val * 100.0 : $val));
        }

        $level = strtoupper(trim((string) ($inputs['risk_level'] ?? $inputs['severity'] ?? $inputs['mode'] ?? 'MEDIUM')));

        return match ($level) {
            'CRITICAL', 'PANIC' => 100.0,
            'HIGH' => 90.0,
            'MEDIUM', 'WARNING' => 60.0,
            'LOW', 'NORMAL' => 25.0,
            default => 50.0,
        };
    }

    /**
     * Normalize monetary value at stake to a 0..100 scale.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function normalizeValueScore(array $inputs): float
    {
        if (isset($inputs['value_score']) && is_numeric($inputs['value_score'])) {
            $val = (float) $inputs['value_score'];
            return max(0.0, min(100.0, $val <= 1.0 && $val > 0.0 ? $val * 100.0 : $val));
        }

        $valueAtStake = (float) ($inputs['value_at_stake'] ?? 0.0);
        $referenceCap = max(1.0, (float) ($inputs['value_cap'] ?? self::DEFAULT_VALUE_AT_STAKE_CAP));

        return max(0.0, min(100.0, ($valueAtStake / $referenceCap) * 100.0));
    }

    /**
     * Normalize time urgency to a 0..100 scale.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function normalizeUrgencyScore(array $inputs): float
    {
        if (isset($inputs['urgency_score']) && is_numeric($inputs['urgency_score'])) {
            $val = (float) $inputs['urgency_score'];
            return max(0.0, min(100.0, $val <= 1.0 && $val > 0.0 ? $val * 100.0 : $val));
        }

        if (isset($inputs['days_remaining']) && is_numeric($inputs['days_remaining'])) {
            $daysLeft = max(0.0, (float) $inputs['days_remaining']);
            $horizon = max(1.0, (float) ($inputs['horizon_days'] ?? 14.0));
            return max(0.0, min(100.0, (1.0 - ($daysLeft / $horizon)) * 100.0));
        }

        $level = strtoupper(trim((string) ($inputs['risk_level'] ?? $inputs['severity'] ?? 'MEDIUM')));
        return match ($level) {
            'CRITICAL', 'PANIC' => 95.0,
            'HIGH' => 85.0,
            'MEDIUM', 'WARNING' => 55.0,
            default => 30.0,
        };
    }
}
