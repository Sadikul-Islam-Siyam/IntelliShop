<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductPromotion;
use App\Services\PromotionEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $promotions = ProductPromotion::with(['customer:id,name,email', 'product:id,name,sku'])
            ->latest('sent_at')
            ->paginate(50);

        return response()->json($promotions);
    }

    public function send(Request $request, PromotionEngineService $service): JsonResponse
    {
        $targetCustomer = null;
        if ($request->filled('customer_id')) {
            $targetCustomer = Customer::findOrFail($request->input('customer_id'));
        }

        $result = $service->run($targetCustomer);

        return response()->json([
            'message' => "Promotion dispatch completed: {$result['dispatched_count']} queued, {$result['skipped_count']} skipped.",
            'data' => $result,
        ]);
    }
}
