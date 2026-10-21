<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

/**
 * UserService
 *
 * Implements user management operations:
 * Create, Read, Update, Deactivate with audit logging.
 */
class UserService
{
    /**
     * Get paginated list of users with optional search/filtering.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = User::with('role');

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        if (!empty($filters['role_id'])) {
            $query->where('role_id', $filters['role_id']);
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Create a new user.
     */
    public function create(array $data, ?int $performedBy = null): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role_id' => $data['role_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        AuditLogger::log(
            $performedBy,
            'users.create',
            'users',
            $user->id,
            ['name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id]
        );

        return $user->load('role');
    }

    /**
     * Update an existing user.
     */
    public function update(User $user, array $data, ?int $performedBy = null): User
    {
        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $updates['email'] = $data['email'];
        }
        if (!empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }
        if (array_key_exists('phone', $data)) {
            $updates['phone'] = $data['phone'];
        }
        if (array_key_exists('role_id', $data)) {
            $updates['role_id'] = $data['role_id'];
        }
        if (array_key_exists('branch_id', $data)) {
            $updates['branch_id'] = $data['branch_id'];
        }
        if (isset($data['status'])) {
            $updates['status'] = $data['status'];
        }

        $user->update($updates);

        AuditLogger::log(
            $performedBy,
            'users.update',
            'users',
            $user->id,
            ['updated_fields' => array_keys($updates)]
        );

        return $user->fresh(['role']);
    }

    /**
     * Deactivate user account.
     */
    public function deactivate(User $user, ?int $performedBy = null): User
    {
        $user->update(['status' => 'inactive']);

        AuditLogger::log(
            $performedBy,
            'users.deactivate',
            'users',
            $user->id,
            ['email' => $user->email]
        );

        return $user;
    }

    /**
     * Activate user account.
     */
    public function activate(User $user, ?int $performedBy = null): User
    {
        $user->update(['status' => 'active']);

        AuditLogger::log(
            $performedBy,
            'users.activate',
            'users',
            $user->id,
            ['email' => $user->email]
        );

        return $user;
    }
}
