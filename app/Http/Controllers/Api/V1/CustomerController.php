<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
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

        $customers = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());
        AuditLogger::log($request->user()->id, 'customers.create', 'customers', $customer->id, $customer->only(['name', 'email', 'segment']));

        return response()->json(['data' => new CustomerResource($customer)], Response::HTTP_CREATED);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'customers.update', 'customers', $customer->id, $request->validated());

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->hasPermission('customers.manage'), Response::HTTP_FORBIDDEN);
        $customerId = $customer->id;
        AuditLogger::log($request->user()->id, 'customers.delete', 'customers', $customerId, $customer->only(['name', 'email', 'segment']));
        $customer->delete();

        return response()->json(['data' => ['id' => $customerId, 'message' => 'Customer deleted.']]);
    }
}
