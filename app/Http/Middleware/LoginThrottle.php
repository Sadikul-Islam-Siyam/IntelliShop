<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * LoginThrottle Middleware
 *
 * Rules:
 * - Lockout after 5 failed logins in 15 minutes
 * - Generic error message only
 * - Counter in cache keyed by email + IP
 * - Returns HTTP 429 with standard JSON payload and Retry-After header
 * - Sensitive audit logging on lockout events
 */
class LoginThrottle
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $throttleKey = $this->resolveThrottleKey($request);
        $maxAttempts = AuthService::maxThrottleAttempts();
        $decaySeconds = AuthService::throttleDecaySeconds();

        // 1. Check if the current email + IP combination is already locked out
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $secondsRemaining = RateLimiter::availableIn($throttleKey);
            $minutesRemaining = max(1, (int) ceil($secondsRemaining / 60));

            // Log security lockout event
            AuditLogger::log(
                null,
                'auth.lockout',
                'users',
                null,
                [
                    'email' => $request->input('email'),
                    'ip' => $request->ip(),
                    'retry_after' => $secondsRemaining,
                ],
                $request->ip()
            );

            $message = "Too many failed login attempts. Please try again in {$minutesRemaining} minutes.";

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'THROTTLED',
                        'message' => $message,
                        'retry_after' => $secondsRemaining,
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => $secondsRemaining,
                    'X-RateLimit-Limit' => $maxAttempts,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }

            return redirect()->to('/login')
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => $message]);
        }

        // 2. Process the request
        try {
            $response = $next($request);
        } catch (ValidationException $e) {
            // Failed credentials validation: hit rate limiter
            $this->handleFailedAttempt($throttleKey, $decaySeconds, $maxAttempts, $request);
            throw $e;
        }

        // 3. Inspect response outcome
        if (auth()->check()) {
            // Successful login clears the failed attempt counter
            RateLimiter::clear($throttleKey);
        } elseif ($response->getStatusCode() === Response::HTTP_UNPROCESSABLE_ENTITY ||
                  $response->getStatusCode() === Response::HTTP_UNAUTHORIZED ||
                  ($response->isRedirection() && !auth()->check())) {
            $this->handleFailedAttempt($throttleKey, $decaySeconds, $maxAttempts, $request);
        }

        return $response;
    }

    /**
     * Increment the rate limiter and log when threshold is reached.
     */
    protected function handleFailedAttempt(string $throttleKey, int $decaySeconds, int $maxAttempts, Request $request): void
    {
        $attempts = RateLimiter::hit($throttleKey, $decaySeconds);

        if ($attempts >= $maxAttempts) {
            AuditLogger::log(
                null,
                'auth.lockout_triggered',
                'users',
                null,
                [
                    'email' => $request->input('email'),
                    'ip' => $request->ip(),
                    'attempts' => $attempts,
                    'lockout_seconds' => $decaySeconds,
                ],
                $request->ip()
            );
        } else {
            AuditLogger::log(
                null,
                'auth.failed_attempt',
                'users',
                null,
                [
                    'email' => $request->input('email'),
                    'ip' => $request->ip(),
                    'attempts' => $attempts,
                ],
                $request->ip()
            );
        }
    }

    /**
     * Resolve the unique rate-limiting throttle key based on email + IP.
     */
    public function resolveThrottleKey(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email', '')));
        $ip = $request->ip() ?: '127.0.0.1';

        return 'login_throttle:' . sha1($email . '|' . $ip);
    }
}
