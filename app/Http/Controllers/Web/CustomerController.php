<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Customer::query()->orderBy('name');
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($segment = $request->query('segment')) {
            $query->where('segment', $segment);
        }

        return view('customers.index', [
            'customers' => $query->paginate(20)->withQueryString(),
            'segments' => Customer::query()->whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment'),
            'canManage' => $request->user()->hasPermission('customers.manage'),
        ]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer()]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create($request->validated());
        AuditLogger::log($request->user()->id, 'customers.create', 'customers', $customer->id, $customer->only(['name', 'email', 'segment']));

        return redirect()->route('customers.index')->with('status', 'Customer created.');
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $customer->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'customers.update', 'customers', $customer->id, $request->validated());

        return redirect()->route('customers.index')->with('status', 'Customer profile updated.');
    }

    public function destroy(Request $request, Customer $customer)
    {
        abort_unless($request->user()->hasPermission('customers.manage'), Response::HTTP_FORBIDDEN);
        $customerId = $customer->id;
        AuditLogger::log($request->user()->id, 'customers.delete', 'customers', $customerId, $customer->only(['name', 'email', 'segment']));
        $customer->delete();

        return redirect()->route('customers.index')->with('status', 'Customer deleted. Historical sales are retained without a customer link.');
    }
}
