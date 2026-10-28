<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $managerRoleId = \App\Models\Role::query()
            ->where('name', \App\Models\Role::BRANCH_MANAGER)
            ->value('id');

        return [
            'name' => ['required', 'string', 'max:100', 'unique:branches,name'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role_id', $managerRoleId ?? -1)],
        ];
    }
}
