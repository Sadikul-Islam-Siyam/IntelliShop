<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * IntelliShop User Model
 *
 * Implements Sub-Feature #2: User Registration & Login (Sharif, Week 1).
 * Schema v2 / Appendix D:
 * Table users: id, name, email, password, phone, role_id, branch_id, status, created_at, updated_at.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Default model attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role_id',
        'branch_id',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'string',
        ];
    }

    /**
     * Relationship: User belongs to a Role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the user has a specific role (by name or slug).
     *
     * @param string|array $roles
     */
    public function hasRole(string|array $roles): bool
    {
        if (!$this->role) {
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];
        $currentName = strtolower(trim($this->role->name));
        $currentSlug = strtolower(trim($this->role->slug));

        foreach ($roles as $role) {
            $target = strtolower(trim($role));
            $targetSlug = strtolower(trim(Str::snake(Str::studly($role))));
            if ($currentName === $target || $currentSlug === $target || $currentSlug === $targetSlug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    /**
     * Check if user is Super Administrator.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * Check if user has a specific permission.
     * Super Admin has access to all permissions (system bypass per Section 2.3).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (!$this->role) {
            return false;
        }

        return $this->role->hasPermission($permission);
    }

    /**
     * Get all effective permissions for this user.
     *
     * @return array<string>
     */
    public function getPermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return Role::allPermissionKeys();
        }

        if (!$this->role) {
            return [];
        }

        return $this->role->permissions;
    }
}
