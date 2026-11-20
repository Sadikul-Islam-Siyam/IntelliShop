<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SubstitutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSubstitutionController extends Controller
{
    public function __construct(
        protected SubstitutionService $substitutionService
    ) {}

    /**
     * Get top cosine-similarity product substitutes for a target product.
     * Endpoint: GET /api/v1/pos/substitutes/{product}
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $branchId = $request->query('branch_id', $request->user()?->branch_id);

        $substitutes = $this->substitutionService->findSubstitutes($product, $branchId ? (int) $branchId : null);

        return response()->json([
            'target_product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category_id' => $product->category_id,
                'selling_price' => (float) $product->selling_price,
                'unit_size' => (float) ($product->unit_size ?? 0.0),
            ],
            'substitutes' => array_map(function ($sub) {
                return [
                    'product_id' => $sub['product']->id,
                    'name' => $sub['product']->name,
                    'sku' => $sub['product']->sku,
                    'selling_price' => (float) $sub['product']->selling_price,
                    'unit_size' => (float) ($sub['product']->unit_size ?? 0.0),
                    'similarity_score' => $sub['similarity_score'],
                    'same_category' => $sub['same_category'],
                    'in_stock' => $sub['in_stock'],
                    'available_quantity' => $sub['available_quantity'],
                ];
            }, $substitutes),
        ]);
    }
}
