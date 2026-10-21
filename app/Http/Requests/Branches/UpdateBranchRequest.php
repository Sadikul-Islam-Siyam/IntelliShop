<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;
        $managerRoleId = \App\Models\Role::query()
            ->where('name', \App\Models\Role::BRANCH_MANAGER)
            ->value('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branchId)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role_id', $managerRoleId ?? -1)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }
}
