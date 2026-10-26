<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureSuperAdmin;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\CorrelationIdMiddleware::class);

        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
            'admin.access' => \App\Http\Middleware\EnsureAdminAccess::class,
            'tenant.domain' => \App\Http\Middleware\ResolveTenantByDomain::class,
            'correlation.id' => \App\Http\Middleware\CorrelationIdMiddleware::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $modelName = class_basename($e->getModel());
                // Convert PascalCase model to readable words, e.g. TenantSubscription -> Tenant Subscription
                $readableModel = trim(preg_replace('/(?<!\ )[A-Z]/', ' $0', $modelName));

                return response()->json([
                    'success' => false,
                    'message' => "Selected {$readableModel} does not exist.",
                    'error' => "Record not found.",
                ], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $previous = $e->getPrevious();
                if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $modelName = class_basename($previous->getModel());
                    $readableModel = trim(preg_replace('/(?<!\ )[A-Z]/', ' $0', $modelName));

                    return response()->json([
                        'success' => false,
                        'message' => "Selected {$readableModel} does not exist.",
                        'error' => "Record not found.",
                    ], 404);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Requested resource or endpoint does not exist.',
                    'error' => 'Not found.',
                ], 404);
            }
        });
    })->create();
