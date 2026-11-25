<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketBasketService
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Mine association rules from completed sale transactions.
     *
     * @param float $minSupport
     * @param float $minConfidence
     * @param float $minLift
     * @return Collection<MarketBasketRule>
     */
    public function mineRules(
        float $minSupport = 0.01,
        float $minConfidence = 0.10,
        float $minLift = 1.00
    ): Collection {
        // Group items by completed sale
        $baskets = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')
            ->select('sale_items.sale_id', 'sale_items.product_id')
            ->get()
            ->groupBy('sale_id')
            ->map(fn($items) => $items->pluck('product_id')->unique()->values()->toArray())
            ->values()
            ->toArray();

        $nTransactions = count($baskets);
        if ($nTransactions === 0) {
            return collect();
        }

        $itemCounts = [];
        $pairCounts = [];

        foreach ($baskets as $basket) {
            sort($basket);
            $n = count($basket);

            for ($i = 0; $i < $n; $i++) {
                $item = $basket[$i];
                $itemCounts[$item] = ($itemCounts[$item] ?? 0) + 1;

                for ($j = $i + 1; $j < $n; $j++) {
                    $pairKey = "{$item}_{$basket[$j]}";
                    $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
                }
            }
        }

        $generated = collect();

        DB::transaction(function () use (
            $pairCounts,
            $itemCounts,
            $nTransactions,
            $minSupport,
            $minConfidence,
            $minLift,
            &$generated
        ) {
            foreach ($pairCounts as $pairKey => $countAb) {
                [$itemA, $itemB] = array_map('intval', explode('_', $pairKey));
                $supportAb = $countAb / $nTransactions;

                if ($supportAb < $minSupport) {
                    continue;
                }

                $pA = ($itemCounts[$itemA] ?? 0) / $nTransactions;
                $pB = ($itemCounts[$itemB] ?? 0) / $nTransactions;

                // Rule A -> B
                $confAb = $pA > 0 ? ($countAb / $itemCounts[$itemA]) : 0.0;
                $liftAb = ($confAb > 0 && $pB > 0) ? ($confAb / $pB) : 0.0;

                if ($confAb >= $minConfidence && $liftAb >= $minLift) {
                    $rule = MarketBasketRule::updateOrCreate(
                        [
                            'antecedent_product_id' => $itemA,
                            'consequent_product_id' => $itemB,
                        ],
                        [
                            'support' => round($supportAb, 4),
                            'confidence' => round($confAb, 4),
                            'lift' => round($liftAb, 4),
                            'generated_at' => now(),
                        ]
                    );
                    $generated->push($rule);
                }

                // Rule B -> A
                $confBa = $pB > 0 ? ($countAb / $itemCounts[$itemB]) : 0.0;
                $liftBa = ($confBa > 0 && $pA > 0) ? ($confBa / $pA) : 0.0;

                if ($confBa >= $minConfidence && $liftBa >= $minLift) {
                    $rule = MarketBasketRule::updateOrCreate(
                        [
                            'antecedent_product_id' => $itemB,
                            'consequent_product_id' => $itemA,
                        ],
                        [
                            'support' => round($supportAb, 4),
                            'confidence' => round($confBa, 4),
                            'lift' => round($liftBa, 4),
                            'generated_at' => now(),
                        ]
                    );
                    $generated->push($rule);
                }
            }
        });

        return $generated;
    }

    /**
     * Get top cross-sell recommendations for a scanned product.
     */
    public function getCrossSellForProduct(Product $product, int $limit = 5): Collection
    {
        return MarketBasketRule::query()
            ->where('antecedent_product_id', $product->id)
            ->with(['consequent:id,name,sku,selling_price'])
            ->orderByDesc('lift')
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get();
    }
}
