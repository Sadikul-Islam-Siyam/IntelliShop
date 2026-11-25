<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Collection;

class SubstitutionService
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Compute cosine similarity between two 3D vectors: [category, price, unit_size].
     */
    public function computeCosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = ($vecA[0] * $vecB[0]) + ($vecA[1] * $vecB[1]) + ($vecA[2] * $vecB[2]);
        $normA = sqrt(($vecA[0] ** 2) + ($vecA[1] ** 2) + ($vecA[2] ** 2));
        $normB = sqrt(($vecB[0] ** 2) + ($vecB[1] ** 2) + ($vecB[2] ** 2));

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        $cosine = $dotProduct / ($normA * $normB);

        return round(min(1.0, max(0.0, $cosine)), 4);
    }

    /**
     * Find top substitute products for an unavailable product.
     *
     * @param Product $product
     * @param int|null $branchId
     * @return array
     */
    public function findSubstitutes(Product $product, ?int $branchId = null): array
    {
        $topK = (int) ($this->settings->get('substitution_top_k', 5) ?? 5);
        $priceBandPct = (float) ($this->settings->get('substitution_price_band_pct', 30.0) ?? 30.0);
        $unitSizeBandPct = (float) ($this->settings->get('substitution_unit_size_band_pct', 50.0) ?? 50.0);
        $categoryWeight = (float) ($this->settings->get('substitution_category_weight', 2.0) ?? 2.0);

        $targetPrice = (float) $product->selling_price;
        $targetUnitSize = (float) ($product->unit_size ?? 0.0);

        // Price band boundaries [P * (1 - band), P * (1 + band)]
        $minPrice = $targetPrice * (1.0 - ($priceBandPct / 100.0));
        $maxPrice = $targetPrice * (1.0 + ($priceBandPct / 100.0));

        // Unit-size band boundaries [U * (1 - band), U * (1 + band)]
        $minUnitSize = $targetUnitSize > 0 ? $targetUnitSize * (1.0 - ($unitSizeBandPct / 100.0)) : 0.0;
        $maxUnitSize = $targetUnitSize > 0 ? $targetUnitSize * (1.0 + ($unitSizeBandPct / 100.0)) : 0.0;

        // Fetch candidate products
        $query = Product::query()
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->whereBetween('selling_price', [$minPrice, $maxPrice]);

        if ($targetUnitSize > 0) {
            $query->where(function ($q) use ($minUnitSize, $maxUnitSize) {
                $q->whereNull('unit_size')
                  ->orWhereBetween('unit_size', [$minUnitSize, $maxUnitSize]);
            });
        }

        $candidates = $query->with(['category', 'unit'])->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        // Global maximums for normalization
        $highestPrice = max($targetPrice, $candidates->max('selling_price') ?: 1.0);
        $highestUnitSize = max($targetUnitSize, $candidates->max('unit_size') ?: 1.0);

        // Target vector A: [category_weight, norm_price, norm_unit_size]
        $vecA = [
            $categoryWeight,
            $highestPrice > 0 ? ($targetPrice / $highestPrice) : 0.0,
            $highestUnitSize > 0 ? ($targetUnitSize / $highestUnitSize) : 0.0,
        ];

        // Stock map if branch is specified
        $stockMap = [];
        if ($branchId) {
            $stockMap = Inventory::where('branch_id', $branchId)
                ->whereIn('product_id', $candidates->pluck('id'))
                ->pluck('quantity_available', 'product_id')
                ->toArray();
        }

        $scoredCandidates = [];

        foreach ($candidates as $candidate) {
            $candPrice = (float) $candidate->selling_price;
            $candUnitSize = (float) ($candidate->unit_size ?? 0.0);
            $sameCategory = ($candidate->category_id === $product->category_id);

            // Candidate vector B
            $vecB = [
                $sameCategory ? $categoryWeight : 0.0,
                $highestPrice > 0 ? ($candPrice / $highestPrice) : 0.0,
                $highestUnitSize > 0 ? ($candUnitSize / $highestUnitSize) : 0.0,
            ];

            $similarity = $this->computeCosineSimilarity($vecA, $vecB);
            $availableQty = $stockMap[$candidate->id] ?? 0;
            $inStock = $availableQty > 0;

            $scoredCandidates[] = [
                'product' => $candidate,
                'similarity_score' => $similarity,
                'same_category' => $sameCategory,
                'in_stock' => $inStock,
                'available_quantity' => $availableQty,
            ];
        }

        // Preference ordering:
        // 1. In-stock items preferred over out-of-stock
        // 2. Same category preferred
        // 3. Similarity score descending
        usort($scoredCandidates, function ($a, $b) {
            if ($a['in_stock'] !== $b['in_stock']) {
                return $b['in_stock'] <=> $a['in_stock'];
            }
            if ($a['same_category'] !== $b['same_category']) {
                return $b['same_category'] <=> $a['same_category'];
            }
            return $b['similarity_score'] <=> $a['similarity_score'];
        });

        return array_slice($scoredCandidates, 0, $topK);
    }
}
