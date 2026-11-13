<?php

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\UseChosenHotel;
use App\Http\Middleware\VerifyWhatsAppWebhookSignature;
use App\Support\Reservations\RoomHoldConflict;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Cross-account reporting is split into its own file so that what is
        // readable across every tenant is listed in one place and can be
        // reviewed as a unit, rather than sitting indented inside the routes
        // every hotel uses.
        //
        // The guard is applied HERE, to the whole file, rather than inside a
        // Route::group() in it. That way a route added to routes/superadmin.php is
        // protected by construction and cannot be left exposed by forgetting
        // to nest it — the stack below is deliberately identical to the one
        // wrapping the tenant routes in routes/api.php, plus super_admin.
        then: function () {
            Route::middleware(['api', 'api.key', 'auth:sanctum', 'throttle:api', 'tenant', 'super_admin'])
                ->prefix('api/superadmin')
                ->group(base_path('routes/superadmin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => Authenticate::class,
            'api.key' => ApiKeyMiddleware::class,
            'tenant' => ResolveTenant::class,
            'not_suspended' => EnsureNotSuspended::class,
            'hotel_choice' => UseChosenHotel::class,
            'not_impersonating' => EnsureNotImpersonating::class,
            'super_admin' => EnsureSuperAdmin::class,
            'feature' => EnsureFeatureEnabled::class,
            'whatsapp.signature' => VerifyWhatsAppWebhookSignature::class,
        ]);

        // Resolve the tenant before route-model binding, so a bound record
        // is looked up under the hotel scope: another hotel's id is simply
        // not found (404), instead of being loaded, validated and then
        // refused by the policy (spec 017 FR-005).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // A switched-off feature answers before any of its records is looked
        // up, so every id gets the same "not enabled" 404 (spec 019).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureFeatureEnabled::class);

        // A suspended hotel leaves the tenant scope before any record is looked
        // up, so its record ids are not found (spec 024).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureNotSuspended::class);

        // An admin's request narrows to one hotel before any record is looked
        // up, so a record in another of their hotels is not found (spec 025).
        $middleware->prependToPriorityList(SubstituteBindings::class, UseChosenHotel::class);

        // An impersonation session is refused before any record is looked up, so
        // the answer doesn't depend on whether the id exists (spec 027).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureNotImpersonating::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A room double-booking that reached the database (a race, or a path
        // without RoomHoldConflict) is the user's 422, never a 500 (spec 020
        // FR-010), with the same body as a ValidationException on `rooms`.
        $exceptions->render(fn (QueryException $e, Request $request) => RoomHoldConflict::matches($e)
            ? response()->json(['message' => RoomHoldConflict::MESSAGE, 'errors' => ['rooms' => [RoomHoldConflict::MESSAGE]]], 422)
            : null);

        // Unhandled errors go to Sentry once each, scrubbed (spec 017 FR-027).
        // With no DSN configured Sentry is off and this does nothing.
        $exceptions->dontReportDuplicates();
        Integration::handles($exceptions);
    })->create();
