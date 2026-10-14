<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthService
 *
 * Implements business logic for Sub-Feature #2: User Registration & Login (Sharif, Week 1).
 * Features:
 * - Credential verification & password hashing check
 * - Account active status guard
 * - Session regeneration against session fixation (Fig 3.1)
 * - Audit logging for authentication events
 * - User registration with secure defaults
 */
class AuthService
{
    /**
     * Authenticate user with credentials, regenerate session, and return auth payload.
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, bool $remember = false, ?Request $request = null): array
    {
        $user = User::with('role')->where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is currently inactive or deactivated. Please contact an administrator.'],
            ]);
        }

        // Perform authentication
        Auth::login($user, $remember);

        // Regenerate session to prevent session fixation attacks (Fig 3.1 / Master Plan)
        if ($request && $request->hasSession()) {
            $request->session()->regenerate();
        } elseif (request()->hasSession()) {
            request()->session()->regenerate();
        }

        // Audit log the successful login
        AuditLogger::log(
            $user->id,
            'auth.login',
            'users',
            $user->id,
            ['email' => $user->email, 'branch_id' => $user->branch_id],
            $request?->ip() ?? request()->ip()
        );

        return [
            'user' => $user,
            'role' => $user->role?->name,
            'permissions' => $user->getPermissions(),
            'redirect' => $this->getDashboardRouteForUser($user),
        ];
    }

    /**
     * Log the user out of the application and invalidate the session.
     */
    public function logout(?Request $request = null): void
    {
        $userId = Auth::id();

        if ($userId) {
            AuditLogger::log(
                $userId,
                'auth.logout',
                'users',
                $userId,
                [],
                $request?->ip() ?? request()->ip()
            );
        }

        Auth::logout();

        $req = $request ?? request();
        if ($req->hasSession()) {
            $req->session()->invalidate();
            $req->session()->regenerateToken();
        }
    }

    /**
     * Register a new user with hashed password and role assignment.
     */
    public function register(array $data, ?int $performedBy = null): User
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
            $performedBy ?? $user->id,
            'users.create',
            'users',
            $user->id,
            ['name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id]
        );

        return $user->load('role');
    }

    /**
     * Determine role-based landing URL for post-login redirection.
     */
    protected function getDashboardRouteForUser(User $user): string
    {
        return '/dashboard';
    }
}
