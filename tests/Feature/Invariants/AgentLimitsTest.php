<?php

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;

/*
 * Every agent declares its own run limits: the provider's cheapest model, a
 * step cap (each step is a paid request) and a per-request timeout, chosen
 * to fit the job or request that runs it.
 */

it('gives every agent the cheapest model, a step cap and a timeout', function () {
    $agents = collect(glob(app_path('Ai/Agents/*.php')))
        ->map(fn (string $path) => 'App\\Ai\\Agents\\'.basename($path, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Agent::class));

    expect($agents)->not->toBeEmpty();

    foreach ($agents as $class) {
        $reflection = new ReflectionClass($class);

        foreach ([UseCheapestModel::class, MaxSteps::class, Timeout::class] as $attribute) {
            expect($reflection->getAttributes($attribute))->toHaveCount(1);
        }
    }
});
