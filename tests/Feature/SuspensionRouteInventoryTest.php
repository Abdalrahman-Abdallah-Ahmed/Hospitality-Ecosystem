<?php

use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Spec 024 (FR-041, risk R4): a route that misses the suspension check would let
| a suspended hotel keep working through it. Fails by route name.
*/

it('has the suspension check on every authenticated route', function () {
    $exempt = ['POST api/logout', 'POST logout'];
    $missing = [];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:sanctum', $middleware, true) || in_array('super_admin', $middleware, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $name = $method.' '.$route->uri();

            if (in_array($name, $exempt, true)) {
                continue;
            }

            $checked++;

            if (! in_array('not_suspended', $middleware, true) || in_array('not_suspended', $route->excludedMiddleware(), true)) {
                $missing[] = $name;
            }
        }
    }

    expect($missing)->toBe([])
        ->and($checked)->toBeGreaterThan(100);
});

it('leaves only logout outside the check', function () {
    $logout = Route::getRoutes()->match(request()->create('/api/logout', 'POST'));

    expect($logout->excludedMiddleware())->toContain('not_suspended');
});

it('runs the check before a record in the URL is looked up', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    expect(array_search(EnsureNotSuspended::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true))
        ->toBeGreaterThan(array_search(ResolveTenant::class, $priority, true));
});

it('works in one chosen hotel on every authenticated route, apart from those named', function () {
    $exempt = ['POST api/logout', 'POST logout', 'GET api/hotel', 'POST api/connect', 'GET api/check-paired'];
    $missing = [];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:sanctum', $middleware, true) || in_array('super_admin', $middleware, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $name = $method.' '.$route->uri();
            $applies = in_array('hotel_choice', $middleware, true) && ! in_array('hotel_choice', $route->excludedMiddleware(), true);

            if (in_array($name, $exempt, true)) {
                expect($applies)->toBeFalse("{$name} is listed as exempt, but runs hotel_choice");

                continue;
            }

            $checked++;

            if (! $applies) {
                $missing[] = $name;
            }
        }
    }

    expect($missing)->toBe([])
        ->and($checked)->toBeGreaterThan(100);
});
