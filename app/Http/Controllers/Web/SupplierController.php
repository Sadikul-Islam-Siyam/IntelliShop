<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    public function index(Request $request): View
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

        return view('suppliers.index', [
            'suppliers' => $query->paginate(20)->withQueryString(),
            'canManage' => $request->user()->hasPermission('suppliers.manage'),
        ]);
    }

    public function create(): View
    {
        return view('suppliers.form', ['supplier' => new Supplier()]);
    }

    public function store(StoreSupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());
        AuditLogger::log($request->user()->id, 'suppliers.create', 'suppliers', $supplier->id, $supplier->only(['name', 'email']));

        return redirect()->route('suppliers.index')->with('status', 'Supplier created.');
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.form', ['supplier' => $supplier]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $supplier->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'suppliers.update', 'suppliers', $supplier->id, $request->validated());

        return redirect()->route('suppliers.index')->with('status', 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        abort_unless($request->user()->hasPermission('suppliers.manage'), Response::HTTP_FORBIDDEN);
        $supplier->update(['status' => 'inactive']);
        AuditLogger::log($request->user()->id, 'suppliers.deactivate', 'suppliers', $supplier->id, ['status' => 'inactive']);

        return redirect()->route('suppliers.index')->with('status', 'Supplier deactivated.');
    }
}
