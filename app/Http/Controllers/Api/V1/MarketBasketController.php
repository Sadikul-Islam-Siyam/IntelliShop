<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Services\MarketBasketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketBasketController extends Controller
{
    public function __construct(
        protected MarketBasketService $basketService
    ) {}

    /**
     * Mine market basket association rules.
     * Endpoint: POST /api/v1/basket/run
     */
    public function run(Request $request): JsonResponse
    {
        $minSupport = (float) ($request->input('min_support', 0.01) ?? 0.01);
        $minConfidence = (float) ($request->input('min_confidence', 0.10) ?? 0.10);
        $minLift = (float) ($request->input('min_lift', 1.00) ?? 1.00);

        $rules = $this->basketService->mineRules($minSupport, $minConfidence, $minLift);

        $loaded = MarketBasketRule::whereIn('id', $rules->pluck('id'))
            ->with(['antecedent:id,name,sku', 'consequent:id,name,sku'])
            ->get();

        return response()->json([
            'message' => "Association rule mining completed. {$loaded->count()} rules stored.",
            'rule_count' => $loaded->count(),
            'data' => $loaded,
        ]);
    }

    /**
     * List existing association rules.
     * Endpoint: GET /api/v1/basket/rules
     */
    public function index(Request $request): JsonResponse
    {
        $query = MarketBasketRule::with(['antecedent:id,name,sku', 'consequent:id,name,sku'])
            ->orderByDesc('lift');

        if ($request->filled('product_id')) {
            $pid = (int) $request->input('product_id');
            $query->where(function ($q) use ($pid) {
                $q->where('antecedent_product_id', $pid)
                  ->orWhere('consequent_product_id', $pid);
            });
        }

        return response()->json($query->paginate(25));
    }

    /**
     * Get cross-sell recommendation for a product at POS.
     * Endpoint: GET /api/v1/crosssell/{product}
     */
    public function crossSell(Request $request, Product $product): JsonResponse
    {
        $limit = (int) ($request->query('limit', 5) ?? 5);

        $crossSellRules = $this->basketService->getCrossSellForProduct($product, $limit);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
            'cross_sells' => $crossSellRules->map(function ($rule) {
                return [
                    'recommended_product' => $rule->consequent,
                    'support' => (float) $rule->support,
                    'confidence' => (float) $rule->confidence,
                    'lift' => (float) $rule->lift,
                ];
            }),
        ]);
    }
}
