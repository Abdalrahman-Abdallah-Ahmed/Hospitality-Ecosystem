<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Infers the Eloquent model a generic request applies to from the
 * controller handling the current route (e.g. HotelController -> Hotel),
 * following this app's existing controller/model naming convention.
 */
trait ResolvesModelFromRoute
{
    protected function modelClass(): string
    {
        $controller = $this->route()?->getController();

        if (! $controller) {
            throw new RuntimeException('Unable to resolve the current route controller.');
        }

        $name = Str::replaceLast('Controller', '', class_basename($controller));
        $class = 'App\\Models\\'.$name;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException("Unable to resolve a model for controller [{$name}Controller].");
        }

        return $class;
    }

    protected function routeModel(): ?Model
    {
        $param = Str::snake(class_basename($this->modelClass()));
        $bound = $this->route($param);

        return $bound instanceof Model ? $bound : null;
    }
}
