<?php

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\VerifyWhatsAppWebhookSignature;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Cross-account reporting is split into its own file so that what is
        // readable across every tenant is listed in one place and can be
        // reviewed as a unit, rather than sitting indented inside the routes
        // every hotel uses.
        //
        // The guard is applied HERE, to the whole file, rather than inside a
        // Route::group() in it. That way a route added to routes/admin.php is
        // protected by construction and cannot be left exposed by forgetting
        // to nest it — the stack below is deliberately identical to the one
        // wrapping the tenant routes in routes/api.php, plus super_admin.
        then: function () {
            Route::middleware(['api', 'api.key', 'auth:sanctum', 'tenant', 'super_admin'])
                ->prefix('api/admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => Authenticate::class,
            'api.key' => ApiKeyMiddleware::class,
            'tenant' => ResolveTenant::class,
            'super_admin' => EnsureSuperAdmin::class,
            'whatsapp.signature' => VerifyWhatsAppWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
