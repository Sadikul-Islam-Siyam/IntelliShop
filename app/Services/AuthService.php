<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AuthService
 *
 * Implements business logic for:
 * - Credential verification & password hashing check
 * - Account active status guard
 * - Rate limiting & lockout after 5 failed attempts in 15 minutes
 * - Session regeneration against session fixation
 * - Audit logging for authentication events
 * - User registration with secure defaults
 */
class AuthService
{
    /**
     * Get the maximum allowed login attempts before lockout.
     */
    public static function maxThrottleAttempts(): int
    {
        return (int) (config('settings.auth.lockout_attempts') ?? config('auth.throttle.max_attempts', 5));
    }

    /**
     * Get the lockout decay duration in seconds (15 minutes = 900s).
     */
    public static function throttleDecaySeconds(): int
    {
        $minutes = (int) (config('settings.auth.lockout_minutes') ?? config('auth.throttle.decay_minutes', 15));
        return $minutes * 60;
    }

    /**
     * Resolve the unique throttle cache key for an email + IP combination.
     */
    public static function throttleKey(string $email, ?string $ip = null): string
    {
        $normalizedEmail = Str::lower(trim($email));
        $resolvedIp = $ip ?: (request()->ip() ?: '127.0.0.1');

        return 'login_throttle:' . sha1($normalizedEmail . '|' . $resolvedIp);
    }

    /**
     * Determine if the given email + IP is currently locked out.
     */
    public function isLockedOut(string $email, ?string $ip = null): bool
    {
        $key = self::throttleKey($email, $ip);
        return RateLimiter::tooManyAttempts($key, self::maxThrottleAttempts());
    }

    /**
     * Clear the throttle counter for an email + IP upon successful login.
     */
    public function clearThrottle(string $email, ?string $ip = null): void
    {
        $key = self::throttleKey($email, $ip);
        RateLimiter::clear($key);
    }

    /**
     * Authenticate user with credentials, regenerate session, and return auth payload.
     *
     * @throws ValidationException|HttpException
     */
    public function login(string $email, string $password, bool $remember = false, ?Request $request = null): array
    {
        $ip = $request?->ip() ?? request()->ip() ?? '127.0.0.1';

        // Pre-check for active lockout
        if ($this->isLockedOut($email, $ip)) {
            $key = self::throttleKey($email, $ip);
            $secondsRemaining = RateLimiter::availableIn($key);
            $minutesRemaining = max(1, (int) ceil($secondsRemaining / 60));

            AuditLogger::log(
                null,
                'auth.lockout',
                'users',
                null,
                ['email' => $email, 'ip' => $ip, 'retry_after' => $secondsRemaining],
                $ip
            );

            throw new HttpException(429, "Too many failed login attempts. Please try again in {$minutesRemaining} minutes.");
        }

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
        $this->clearThrottle($email, $ip);

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
    public function getDashboardRouteForUser(User $user): string
    {
        $roleName = $user->role?->name;
        $map = config('permissions.role_redirect_map', []);

        if ($roleName && isset($map[$roleName])) {
            return $map[$roleName];
        }

        return '/dashboard';
    }
}
