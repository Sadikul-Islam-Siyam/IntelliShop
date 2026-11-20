<?php

namespace App\Services;

use App\Contracts\AnalyticsClient;
use App\Models\Branch;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * NlqIntentService (Sub-Feature #60)
 *
 * Classifies natural-language business questions into a fixed whitelist of
 * supported read-only business intents and extracts structured slots
 * (branch, date_range, product, sku, risk_level, limit).
 *
 * Delegates to the Python analytics service (`POST /nlq/resolve`) and
 * provides a deterministic PHP fallback so it operates reliably in all environments.
 */
class NlqIntentService
{
    public const SAFE_FALLBACK_MESSAGE = 'I could not match your question to a supported read-only business query. You can ask about sales summaries, top-selling products, inventory stock balances, low-stock alerts, stock-out risk, slow-moving/overstock items, expiring batches & markdowns, supplier rankings, sales anomalies, phantom inventory, or cross-sell rules.';

    public function __construct(
        protected AnalyticsClient $analyticsClient
    ) {}

    /**
     * Resolve intent and slots for a natural-language business question.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $query, ?string $referenceDate = null): array
    {
        $payload = [
            'query' => $query,
        ];
        if ($referenceDate !== null) {
            $payload['reference_date'] = $referenceDate;
        }

        try {
            $result = $this->analyticsClient->call('/nlq/resolve', $payload);
        } catch (\Throwable $e) {
            Log::warning('Analytics /nlq/resolve failed, using PHP fallback: ' . $e->getMessage());
            $result = $this->resolveFallback($query, $referenceDate);
        }

        $fallback = $this->resolveFallback($query, $referenceDate);
        $merged = array_merge($fallback, $result);

        // Enrich branch & product database IDs if matching records exist
        $slots = is_array($merged['slots'] ?? null) ? $merged['slots'] : (is_array($merged['filters'] ?? null) ? $merged['filters'] : []);
        $slots = $this->enrichDatabaseEntities($slots, $query);

        $merged['slots'] = $slots;
        $merged['filters'] = $slots;

        return $merged;
    }

    /**
     * Pure PHP deterministic intent classifier & slot extractor matching `analytics-service/nlq/intent_resolver.py`.
     *
     * @return array<string, mixed>
     */
    public function resolveFallback(string $query, ?string $referenceDate = null): array
    {
        $cleaned = trim($query);
        if ($cleaned === '') {
            return [
                'query' => '',
                'intent' => 'unknown',
                'confidence' => 0.0,
                'slots' => [],
                'filters' => [],
                'is_safe' => true,
                'fallback_message' => self::SAFE_FALLBACK_MESSAGE,
            ];
        }

        // Unsafe SQL check
        $unsafePatterns = [
            '/\b(drop|truncate|alter|grant|revoke)\s+(table|database|schema|user)\b/i',
            '/\bdelete\s+from\b/i',
            '/\binsert\s+into\b/i',
            '/\bupdate\s+[a-z0-9_]+\s+set\b/i',
            '/;\s*--/i',
            '/\bunion\s+select\b/i',
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                return [
                    'query' => $cleaned,
                    'intent' => 'unknown',
                    'confidence' => 0.0,
                    'slots' => [],
                    'filters' => [],
                    'is_safe' => false,
                    'fallback_message' => 'Unsafe or unsupported query syntax detected. Only natural-language read-only business questions are permitted.',
                ];
            }
        }

        $intentRules = [
            'market_basket_cross_sell' => [
                ['/\b(cross[\s\-]?sell|market\s+basket|association\s+rule|bought\s+together|sells?\s+with|paired\s+with|affinity)\b/i', 0.90],
                ['/\b(frequently\s+bought|bundle|complementary\s+products?)\b/i', 0.80],
            ],
            'phantom_inventory' => [
                ['/\b(phantom\s+inventory|phantom\s+stock|cycle[\s\-]?count|unrecorded\s+shrinkage|ghost\s+stock)\b/i', 0.92],
                ['/\b(zero\s+sales\s+with\s+stock|missing\s+from\s+shelf|variance\s+reconciliation)\b/i', 0.82],
            ],
            'sales_anomalies' => [
                ['/\b(anomal(?:y|ies)|unusual\s+sales|sales\s+spikes?|abnormal\s+sales|modified\s+z[\s\-]?score|iqr\s+fences?|outlier\s+sales)\b/i', 0.92],
                ['/\b(unexpected\s+surge|sudden\s+drop|weird\s+sales)\b/i', 0.78],
            ],
            'expiring_batches_markdown' => [
                ['/\b(zero[\s\-]?waste|markdown|expir(?:ing|y|ed)|near\s+expiry|clearance\s+discount|perishable\s+surplus)\b/i', 0.92],
                ['/\b(batches?\s+expiring|shelf\s+life\s+risk|spoil(?:age|ing)?)\b/i', 0.84],
            ],
            'overstock_slow_moving' => [
                ['/\b(overstock(?:ed)?|slow[\s\-]?moving|dead\s+stock|low\s+sell[\s\-]?through|excess\s+stock|excess\s+inventory)\b/i', 0.92],
                ['/\b(not\s+selling|stagnant\s+stock|surplus\s+inventory)\b/i', 0.80],
            ],
            'supplier_ranking' => [
                ['/\b(supplier\s+ranking|best\s+suppliers?|top\s+suppliers?|evaluate\s+suppliers?|saw\s+score|vendor\s+ranking|fulfillment\s+rate)\b/i', 0.92],
                ['/\b(which\s+supplier|preferred\s+supplier|cheapest\s+supplier|fastest\s+supplier)\b/i', 0.84],
            ],
            'stock_out_risk' => [
                ['/\b(stock[\s\-]?out\s+risk|risk\s+of\s+stock[\s\-]?out|days\s+of\s+cover|expected\s+stock[\s\-]?out|run\s+out\s+of\s+stock|depletion\s+date|stock\s+projection)\b/i', 0.92],
                ['/\b(high\s+risk|stock[\s\-]?outs?|risk\s+classification|replenishment\s+risk)\b/i', 0.82],
            ],
            'low_stock_alerts' => [
                ['/\b(low[\s\-]?stock|below\s+reorder\s+point|reorder\s+alerts?|running\s+low|need\s+restock(?:ing)?|out\s+of\s+stock)\b/i', 0.88],
                ['/\b(shortage|minimum\s+stock)\b/i', 0.75],
            ],
            'top_selling_products' => [
                ['/\b(top\s+\d*\s*selling|best[\s\-]?sell(?:ing|ers)|most\s+sold|highest\s+selling|popular\s+products?|top\s+products?)\b/i', 0.90],
            ],
            'inventory_summary' => [
                ['/\b(inventory\s+report|inventory\s+summary|stock\s+balance|stock\s+valuation|inventory\s+valuation|on[\s\-]?hand\s+stock|current\s+stock|how\s+much\s+stock|warehouse\s+stock)\b/i', 0.86],
                ['/\b(how\s+many\s+units\s+in\s+stock|batches\s+in\s+stock)\b/i', 0.80],
            ],
            'sales_summary' => [
                ['/\b(total\s+sales|sales\s+report|sales\s+summary|revenue|turnover|how\s+much\s+did\s+we\s+sell|daily\s+sales|sales\s+performance|gross\s+sales)\b/i', 0.88],
                ['/\b(sales\s+for|sales\s+in|sales\s+at|revenue\s+for|revenue\s+at|transactions\s+today)\b/i', 0.82],
            ],
        ];

        $bestIntent = 'unknown';
        $bestScore = 0.0;

        foreach ($intentRules as $intentName => $rules) {
            $score = 0.0;
            $matches = 0;
            foreach ($rules as [$regex, $weight]) {
                if (preg_match($regex, $cleaned)) {
                    $score = max($score, $weight);
                    $matches++;
                }
            }
            if ($matches > 1) {
                $score = min(0.99, $score + 0.05 * ($matches - 1));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIntent = $intentName;
            }
        }

        $refDate = $referenceDate ? Carbon::parse($referenceDate) : Carbon::today();
        $slots = $this->extractSlots($cleaned, $refDate);

        if ($bestIntent === 'unknown' || $bestScore < 0.50) {
            return [
                'query' => $cleaned,
                'intent' => 'unknown',
                'confidence' => 0.0,
                'slots' => $slots,
                'filters' => $slots,
                'is_safe' => true,
                'fallback_message' => self::SAFE_FALLBACK_MESSAGE,
            ];
        }

        $slotBoost = !empty($slots) ? 0.03 : 0.0;
        $finalConfidence = round(min(0.99, $bestScore + $slotBoost), 2);

        return [
            'query' => $cleaned,
            'intent' => $bestIntent,
            'confidence' => $finalConfidence,
            'slots' => $slots,
            'filters' => $slots,
            'is_safe' => true,
            'fallback_message' => null,
        ];
    }

    /**
     * Extract slots (branch, date_range, product, sku, limit, risk_level) from question text.
     *
     * @return array<string, mixed>
     */
    protected function extractSlots(string $text, Carbon $refDate): array
    {
        $slots = [];

        // 1. Branch slot
        if (preg_match('/\bbranch\s*(?:id\s*)?#?(\d+)\b/i', $text, $m)) {
            $slots['branch_id'] = (int) $m[1];
            $slots['branch'] = 'Branch ' . $m[1];
        } elseif (preg_match('/\b([A-Z][a-zA-Z0-9\-]+)\s+Branch\b/', $text, $m)) {
            $candidate = trim($m[1]);
            if (!in_array(strtolower($candidate), ['which', 'what', 'every', 'each', 'all', 'this', 'that', 'our'], true)) {
                $slots['branch'] = $candidate . ' Branch';
            }
        }

        // 2. Date range slot
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\s+(?:to|through|until|and|-)\s+(\d{4}-\d{2}-\d{2})\b/i', $text, $m)) {
            $slots['date_range'] = ['period' => 'custom', 'start_date' => $m[1], 'end_date' => $m[2]];
            $slots['start_date'] = $m[1];
            $slots['end_date'] = $m[2];
        } elseif (preg_match('/\btoday\b/i', $text)) {
            $d = $refDate->toDateString();
            $slots['date_range'] = ['period' => 'today', 'days' => 1, 'start_date' => $d, 'end_date' => $d];
            $slots['start_date'] = $d;
            $slots['end_date'] = $d;
            $slots['days'] = 1;
        } elseif (preg_match('/\byesterday\b/i', $text)) {
            $d = $refDate->copy()->subDay()->toDateString();
            $slots['date_range'] = ['period' => 'yesterday', 'days' => 1, 'start_date' => $d, 'end_date' => $d];
            $slots['start_date'] = $d;
            $slots['end_date'] = $d;
            $slots['days'] = 1;
        } elseif (preg_match('/\b(?:last|past|previous|next)\s+(\d+)\s+days?\b/i', $text, $m)) {
            $days = max(1, (int) $m[1]);
            $start = $refDate->copy()->subDays($days)->toDateString();
            $end = $refDate->toDateString();
            $slots['date_range'] = ['period' => "last_{$days}_days", 'days' => $days, 'start_date' => $start, 'end_date' => $end];
            $slots['start_date'] = $start;
            $slots['end_date'] = $end;
            $slots['days'] = $days;
        } elseif (preg_match('/\b(this\s+week|last\s+week|past\s+week|weekly)\b/i', $text)) {
            $start = $refDate->copy()->subDays(7)->toDateString();
            $end = $refDate->toDateString();
            $slots['date_range'] = ['period' => 'last_7_days', 'days' => 7, 'start_date' => $start, 'end_date' => $end];
            $slots['start_date'] = $start;
            $slots['end_date'] = $end;
            $slots['days'] = 7;
        } elseif (preg_match('/\b(this\s+month|last\s+month|past\s+month|monthly)\b/i', $text)) {
            $start = $refDate->copy()->subDays(30)->toDateString();
            $end = $refDate->toDateString();
            $slots['date_range'] = ['period' => 'last_30_days', 'days' => 30, 'start_date' => $start, 'end_date' => $end];
            $slots['start_date'] = $start;
            $slots['end_date'] = $end;
            $slots['days'] = 30;
        }

        // 3. Product / SKU slot
        if (preg_match('/\bsku[:\s]+([A-Z0-9\-]{3,20})\b/i', $text, $m) || preg_match('/\b([A-Z]{2,5}-\d{2,6})\b/', $text, $m)) {
            $slots['sku'] = strtoupper($m[1]);
            $slots['product'] = strtoupper($m[1]);
        } elseif (preg_match('/["\']([^"\']{2,60})["\']/', $text, $m)) {
            $slots['product'] = trim($m[1]);
        }

        // 4. Limit & Risk Level
        if (preg_match('/\b(?:top|first|limit)\s+(\d+)\b/i', $text, $m)) {
            $slots['limit'] = max(1, min(100, (int) $m[1]));
        }
        if (preg_match('/\b(high|medium|low|critical)\s+risk\b/i', $text, $m)) {
            $lvl = strtoupper($m[1]);
            $slots['risk_level'] = $lvl === 'CRITICAL' ? 'HIGH' : $lvl;
        }

        return $slots;
    }

    /**
     * Match extracted branch/product text against database records when available.
     *
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    protected function enrichDatabaseEntities(array $slots, string $rawQuery): array
    {
        try {
            if (!isset($slots['branch_id']) && !empty($slots['branch'])) {
                $branch = Branch::query()
                    ->where('name', 'like', '%' . $slots['branch'] . '%')
                    ->first();
                if ($branch) {
                    $slots['branch_id'] = $branch->id;
                    $slots['branch'] = $branch->name;
                }
            }

            if (!isset($slots['product_id'])) {
                if (!empty($slots['sku'])) {
                    $product = Product::query()->where('sku', $slots['sku'])->first();
                    if ($product) {
                        $slots['product_id'] = $product->id;
                        $slots['product'] = $product->name;
                    }
                } elseif (!empty($slots['product'])) {
                    $product = Product::query()->where('name', 'like', '%' . $slots['product'] . '%')->first();
                    if ($product) {
                        $slots['product_id'] = $product->id;
                        $slots['product'] = $product->name;
                        $slots['sku'] = $product->sku;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore DB lookup errors if running in pure unit tests without migrations
        }

        return $slots;
    }
}
