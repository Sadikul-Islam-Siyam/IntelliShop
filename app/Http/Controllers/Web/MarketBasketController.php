<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Services\MarketBasketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketBasketController extends Controller
{
    public function __construct(
        protected MarketBasketService $basketService
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $products = Product::query()->where('status', 'active')->orderBy('name')->get();
        $selectedProductId = $request->filled('product_id') ? (int) $request->input('product_id') : ($products->first()?->id ?? null);
        $selectedProduct = $selectedProductId ? Product::find($selectedProductId) : null;

        $rules = MarketBasketRule::query()
            ->with(['antecedent:id,name,sku,selling_price', 'consequent:id,name,sku,selling_price'])
            ->when($request->filled('filter_product_id'), function ($q) use ($request) {
                $pid = (int) $request->input('filter_product_id');
                $q->where('antecedent_product_id', $pid)->orWhere('consequent_product_id', $pid);
            })
            ->orderByDesc('lift')
            ->orderByDesc('confidence')
            ->get();

        $crossSellWidgetRules = $selectedProduct
            ? $this->basketService->getCrossSellForProduct($selectedProduct, 5)
            : collect();

        return view('market_basket.index', [
            'user' => $user,
            'products' => $products,
            'selectedProduct' => $selectedProduct,
            'rules' => $rules,
            'crossSellWidgetRules' => $crossSellWidgetRules,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $minSupport = (float) ($request->input('min_support', 0.01) ?? 0.01);
        $minConfidence = (float) ($request->input('min_confidence', 0.10) ?? 0.10);
        $minLift = (float) ($request->input('min_lift', 1.00) ?? 1.00);

        $mined = $this->basketService->mineRules($minSupport, $minConfidence, $minLift);

        return redirect()->route('market-basket.index')
            ->with('success', "Association rule mining completed. {$mined->count()} cross-sell rules stored.");
    }
}
