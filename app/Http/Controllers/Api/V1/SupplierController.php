<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->orderBy('name');
        if (!$request->user()->hasPermission('suppliers.manage')) {
            $query->where('status', 'active');
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $suppliers = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => SupplierResource::collection($suppliers->items()),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
                'last_page' => $suppliers->lastPage(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());
        AuditLogger::log($request->user()->id, 'suppliers.create', 'suppliers', $supplier->id, $supplier->only(['name', 'email']));

        return response()->json(['data' => new SupplierResource($supplier)], Response::HTTP_CREATED);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->hasPermission('suppliers.manage') || $supplier->status === 'active', Response::HTTP_NOT_FOUND);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'suppliers.update', 'suppliers', $supplier->id, $request->validated());

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->hasPermission('suppliers.manage'), Response::HTTP_FORBIDDEN);
        $supplier->update(['status' => 'inactive']);
        AuditLogger::log($request->user()->id, 'suppliers.deactivate', 'suppliers', $supplier->id, ['status' => 'inactive']);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }
}
