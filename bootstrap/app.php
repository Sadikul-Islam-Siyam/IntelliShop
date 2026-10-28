<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'login.throttle' => \App\Http\Middleware\LoginThrottle::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'analytics.token' => \App\Http\Middleware\AnalyticsServiceAuth::class,
        ]);

        // Stateful session cookie support for API routes
        $middleware->group('api', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Standardized IntelliShop REST API error format (Section 20.2)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $details = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $issue) {
                        $details[] = [
                            'field' => $field,
                            'issue' => $issue,
                        ];
                    }
                }

                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => $e->getMessage(),
                        'details' => $details,
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Unauthenticated.',
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $statusCodes = [
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    419 => 'SESSION_EXPIRED',
                    429 => 'THROTTLED',
                    500 => 'INTERNAL_SERVER_ERROR',
                ];

                $code = $statusCodes[$e->getStatusCode()] ?? 'ERROR';

                $payload = [
                    'error' => [
                        'code' => $code,
                        'message' => $e->getMessage() ?: 'An error occurred.',
                    ],
                ];

                if ($e->getStatusCode() === 429) {
                    $headers = $e->getHeaders();
                    if (isset($headers['Retry-After'])) {
                        $payload['error']['retry_after'] = (int) $headers['Retry-After'];
                    }
                }

                return response()->json($payload, $e->getStatusCode(), $e->getHeaders());
            }
        });
    })->create();
