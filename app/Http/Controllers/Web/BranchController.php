<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreBranchRequest;
use App\Http\Requests\Branches\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        $query = Branch::with('manager:id,name,email')->orderBy('name');
        if (!$request->user()->isSuperAdmin()) {
            $query->whereKey($request->user()->branch_id);
        }

        return view('branches.index', [
            'branches' => $query->paginate(20)->withQueryString(),
            'canManage' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function create(): View
    {
        return view('branches.form', ['branch' => new Branch(), 'managers' => $this->managers()]);
    }

    public function store(StoreBranchRequest $request)
    {
        $branch = Branch::create($request->validated());
        AuditLogger::log($request->user()->id, 'branches.create', 'branches', $branch->id, $branch->only(['name', 'manager_id']));

        return redirect()->route('branches.index')->with('status', 'Branch created.');
    }

    public function edit(Branch $branch): View
    {
        return view('branches.form', ['branch' => $branch, 'managers' => $this->managers()]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $branch->fill($request->validated())->save();
        AuditLogger::log($request->user()->id, 'branches.update', 'branches', $branch->id, $request->validated());

        return redirect()->route('branches.index')->with('status', 'Branch updated.');
    }

    public function destroy(Request $request, Branch $branch)
    {
        abort_unless($request->user()->isSuperAdmin(), Response::HTTP_FORBIDDEN);
        $branch->update(['status' => 'inactive']);
        AuditLogger::log($request->user()->id, 'branches.deactivate', 'branches', $branch->id, ['status' => 'inactive']);

        return redirect()->route('branches.index')->with('status', 'Branch deactivated.');
    }

    private function managers()
    {
        $roleId = Role::query()->where('name', Role::BRANCH_MANAGER)->value('id');

        return User::query()->where('role_id', $roleId)->orderBy('name')->get(['id', 'name', 'email']);
    }
}
