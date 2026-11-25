<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Services\SupplierEvaluationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierEvaluationController extends Controller
{
    public function __construct(
        protected SupplierEvaluationService $evaluationService
    ) {}

    /**
     * Render the Supplier Evaluation & SAW Ranking workspace (#47, #48, #68).
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();
        $products = Product::query()->where('status', 'active')->orderBy('name')->get();

        $selectedBranchId = (int) ($request->input('branch_id')
            ?: $user?->branch_id
            ?: ($branches->first()?->id ?? 1));

        $selectedProductId = (int) ($request->input('product_id')
            ?: ($products->first()?->id ?? 1));

        $overrides = [];
        if ($request->filled('required_quantity')) {
            $overrides['required_quantity'] = (float) $request->input('required_quantity');
        }
        if ($request->filled('mode')) {
            $overrides['mode'] = strtoupper((string) $request->input('mode'));
        }

        $evaluation = $products->isNotEmpty()
            ? $this->evaluationService->evaluate($selectedProductId, $selectedBranchId, $overrides)
            : [
                'mode' => 'NORMAL',
                'weights' => ['price' => 0.70, 'speed' => 0.10, 'reliability' => 0.20],
                'stock' => 0.0,
                'rop' => 0.0,
                'required_quantity' => 10,
                'max_acceptable_lead_days' => 14,
                'ranking' => [],
                'ineligible' => [],
                'alert' => null,
            ];

        $selectedProduct = $products->firstWhere('id', $selectedProductId);

        return view('suppliers.evaluation', [
            'user' => $user,
            'branches' => $branches,
            'products' => $products,
            'selectedBranchId' => $selectedBranchId,
            'selectedProductId' => $selectedProductId,
            'selectedProduct' => $selectedProduct,
            'evaluation' => $evaluation,
        ]);
    }
}

