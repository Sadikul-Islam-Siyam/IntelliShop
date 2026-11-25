<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductPromotion;
use App\Services\PromotionEngineService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(Request $request): View
    {
        $promotions = ProductPromotion::with(['customer', 'product'])
            ->latest('sent_at')
            ->paginate(25);

        $totalSent = ProductPromotion::where('status', 'sent')->count();
        $totalOpened = ProductPromotion::where('opened', true)->count();
        $totalCustomers = Customer::whereNotNull('email')->count();

        return view('promotions.index', compact('promotions', 'totalSent', 'totalOpened', 'totalCustomers'));
    }

    public function send(Request $request, PromotionEngineService $service)
    {
        $targetCustomer = null;
        if ($request->filled('customer_id')) {
            $targetCustomer = Customer::findOrFail($request->input('customer_id'));
        }

        $result = $service->run($targetCustomer);

        return redirect()->route('promotions.index')->with(
            'success',
            "Promotions processed: {$result['dispatched_count']} queued/sent, {$result['skipped_count']} customers skipped (no active discount match)."
        );
    }
}
