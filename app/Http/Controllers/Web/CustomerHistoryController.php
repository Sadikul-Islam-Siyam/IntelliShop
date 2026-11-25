<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerHistoryController extends Controller
{
    /**
     * GET /history
     * Customer Purchase History workspace with customer selector.
     */
    public function index(Request $request): View
    {
        $customers = Customer::query()->orderBy('name')->get();
        $selectedCustomerId = (int) ($request->input('customer_id') ?: ($customers->first()?->id ?? 0));
        $customer = $selectedCustomerId > 0
            ? Customer::query()->find($selectedCustomerId)
            : $customers->first();

        if ($customer) {
            return $this->renderHistoryView($request, $customer, $customers);
        }

        return view('customers.history', [
            'user' => $request->user(),
            'customers' => $customers,
            'customer' => null,
            'sales' => collect(),
            'totalOrders' => 0,
            'totalSpent' => 0.0,
            'totalItems' => 0,
        ]);
    }

    /**
     * GET /customers/{customer}/history
     * Purchase History tab for a specific customer (#29).
     */
    public function show(Request $request, Customer $customer): View
    {
        $customers = Customer::query()->orderBy('name')->get();

        return $this->renderHistoryView($request, $customer, $customers);
    }

    protected function renderHistoryView(Request $request, Customer $customer, $customers): View
    {
        $sales = Sale::query()
            ->with(['items.product', 'branch', 'cashier'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->get();

        return view('customers.history', [
            'user' => $request->user(),
            'customers' => $customers,
            'customer' => $customer,
            'sales' => $sales,
            'totalOrders' => $sales->count(),
            'totalSpent' => round((float) $sales->sum('total_amount'), 2),
            'totalItems' => (int) $sales->sum(fn (Sale $s) => $s->items->sum('quantity')),
        ]);
    }
}

