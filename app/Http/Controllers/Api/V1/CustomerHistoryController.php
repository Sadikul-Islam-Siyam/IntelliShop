<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerHistoryController extends Controller
{
    /**
     * GET /api/v1/customers/{customer}/history
     * List all sales and line items for a given customer (#29).
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $query = Sale::query()
            ->with(['items.product', 'branch', 'cashier'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $sales = $query->get();

        $formattedSales = $sales->map(function (Sale $sale): array {
            return [
                'id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'customer_id' => $sale->customer_id,
                'branch_id' => $sale->branch_id,
                'branch_name' => $sale->branch?->name,
                'cashier_id' => $sale->cashier_id,
                'cashier_name' => $sale->cashier?->name,
                'sale_date' => $sale->sale_date?->toIso8601String(),
                'subtotal' => (float) $sale->subtotal,
                'discount_amount' => (float) $sale->discount_amount,
                'tax_amount' => (float) $sale->tax_amount,
                'total_amount' => (float) $sale->total_amount,
                'payment_method' => $sale->payment_method,
                'status' => $sale->status,
                'items_count' => (int) $sale->items->sum('quantity'),
                'items' => $sale->items->map(function ($item): array {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name ?? 'Unknown Product',
                        'sku' => $item->product?->sku,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'discount' => (float) ($item->discount ?? 0.0),
                        'subtotal' => (float) $item->subtotal,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        AuditLogger::log(
            $request->user()?->id,
            'customers.history.view',
            'customers',
            $customer->id,
            ['sales_count' => count($formattedSales)],
            $request->ip()
        );

        $completedSales = $sales->where('status', 'completed');
        $summary = [
            'customer_id' => $customer->id,
            'total_orders' => count($formattedSales),
            'completed_orders' => $completedSales->count(),
            'total_spent' => round((float) $completedSales->sum('total_amount'), 2),
            'total_items' => (int) $completedSales->sum(fn (Sale $s) => $s->items->sum('quantity')),
        ];

        return response()->json([
            'data' => $formattedSales,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'segment' => $customer->segment,
                'loyalty_points' => (int) $customer->loyalty_points,
            ],
            'summary' => $summary,
            'meta' => $summary,
        ]);
    }
}

