<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\MarketBasketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CrossSellController (Sub-Feature #56)
 *
 * Serves top market-basket cross-sell product suggestions for the POS terminal
 * and product detail widgets (`GET /api/v1/crosssell/{product}`).
 */
class CrossSellController extends Controller
{
    public function __construct(
        protected MarketBasketService $basketService
    ) {}

    /**
     * GET /api/v1/crosssell/{product}
     * Retrieve top cross-sell recommendations for a scanned or viewed product.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $limit = max(1, min(20, (int) ($request->query('limit', 5) ?? 5)));
        $branchId = $request->filled('branch_id')
            ? (int) $request->query('branch_id')
            : ($request->user()?->branch_id ? (int) $request->user()->branch_id : null);

        $crossSellRules = $this->basketService->getCrossSellForProduct($product, $limit);

        $crossSells = $crossSellRules->map(function ($rule) use ($branchId) {
            $consequent = $rule->consequent;
            $availableStock = null;

            if ($consequent) {
                $invQuery = Inventory::query()->where('product_id', $consequent->id);
                if ($branchId !== null) {
                    $invQuery->where('branch_id', $branchId);
                }
                $availableStock = (int) $invQuery->sum('quantity_available');
            }

            return [
                'rule_id' => $rule->id,
                'recommended_product' => $consequent ? [
                    'id' => $consequent->id,
                    'name' => $consequent->name,
                    'sku' => $consequent->sku,
                    'selling_price' => (float) $consequent->selling_price,
                    'available_stock' => $availableStock,
                    'in_stock' => $availableStock === null ? true : ($availableStock > 0),
                ] : null,
                'support' => (float) $rule->support,
                'confidence' => (float) $rule->confidence,
                'lift' => (float) $rule->lift,
            ];
        })->values();

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'selling_price' => (float) $product->selling_price,
            ],
            'cross_sells' => $crossSells,
            'data' => $crossSells,
            'meta' => [
                'product_id' => $product->id,
                'count' => $crossSells->count(),
                'limit' => $limit,
            ],
        ]);
    }
}
