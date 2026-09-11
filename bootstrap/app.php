<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureFreshAdminMfa;
use App\Http\Middleware\EnsureUserNotSanctioned;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequireAdminPermission;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectUsersTo(fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin' : '/');
        $middleware->validateCsrfTokens(except: ['webhooks/v1/*']);
        $middleware->alias(['auth.admin' => AuthenticateAdmin::class, 'admin.mfa' => EnsureAdminMfa::class, 'admin.mfa.fresh' => EnsureFreshAdminMfa::class, 'admin.permission' => RequireAdminPermission::class, 'not.sanctioned' => EnsureUserNotSanctioned::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('admin/*') && $exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 503], true)) {
                $status = $exception->getStatusCode();
                $response = Inertia::render('Admin/ErrorState', [
                    'status' => $status,
                    'title' => $status === 403 ? 'Permission required' : 'Service temporarily degraded',
                    'message' => $status === 403 ? 'Your administrator role does not include access to this workspace. No protected record details were disclosed.' : 'A required platform dependency is unavailable. Existing safe data has been preserved; retry when the service recovers.',
                    'retryable' => $status === 503,
                ])->toResponse($request);

                return $response->setStatusCode($status);
            }
            if (! $request->is('api/*') && ! $request->is('webhooks/*')) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => ApiResponse::error('VALIDATION', 'The submitted data is invalid.', 422, $exception->errors()),
                $exception instanceof AuthenticationException => ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', 401),
                $exception instanceof ModelNotFoundException => ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404),
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403 => ApiResponse::error('FORBIDDEN', 'You are not allowed to perform this action.', 403),
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429 => ApiResponse::error('RATE_LIMITED', 'Too many requests.', 429),
                default => null,
            };
        });
    })->create();
