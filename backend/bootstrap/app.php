<?php

use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureUserIsStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The mobile client sends Accept: application/json on every request, but an
        // /api/* call from curl or a gateway webhook does not -- both must still get
        // JSON, never an HTML error page and never a redirect to a login route the
        // API does not own.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        /**
         * `errors` is cast to an object so it serialises as {} and not []:
         * ApiError.errors is typed Record<string, string[]> in the client.
         */
        $json = static fn (string $message, int $status): JsonResponse => response()->json([
            'message' => $message,
            'errors' => (object) [],
        ], $status);

        // Laravel's default JSON rendering omits `errors` outside of 422 and, with
        // APP_DEBUG on, swaps the message for an exception dump. normalizeError() in
        // mobile/src/api/client.ts reads exactly response.data.message and
        // response.data.errors, so these three shapes are pinned here instead.
        $exceptions->render(function (Throwable $e, Request $request) use ($json): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;   // let the admin panel render its HTML error pages
            }

            return match (true) {
                $e instanceof AuthenticationException => $json('Unauthenticated.', 401),

                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => $json(
                    $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                    403,
                ),

                // Route-model binding throws ModelNotFound, which the framework
                // rewrites to NotFoundHttp; catching both means a bare find()
                // failure inside a controller reads identically to the client.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $json('Resource not found.', 404),

                // ValidationException already emits { message, errors } and 5xx
                // keeps framework handling, including the debug payload locally.
                default => null,
            };
        });
    })->create();
