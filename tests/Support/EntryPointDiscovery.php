<?php

namespace Tests\Support;

use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\AuditedTool;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Ai\Contracts\Tool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Finds every way into tenant data from the framework itself, so the
 * inventory (tests/Inventory) cannot go stale: a new route, job, scheduled
 * task or AI tool shows up here the moment it exists (spec 017, R1).
 */
class EntryPointDiscovery
{
    /**
     * Every route that runs in a tenant context, plus the WhatsApp webhook,
     * keyed "METHOD uri" (one entry per method; HEAD is folded into GET).
     *
     * @return Collection<string, Route>
     */
    public static function tenantRoutes(): Collection
    {
        $routes = collect();

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $isTenant = in_array('tenant', $middleware, true) || in_array('super_admin', $middleware, true);
            $isWebhook = $route->uri() === 'api/whatsapp';

            if (! $isTenant && ! $isWebhook) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes->put($method.' '.$route->uri(), $route);
            }
        }

        return $routes->sortKeys();
    }

    /**
     * @return list<class-string>
     */
    public static function queuedJobs(): array
    {
        return array_values(array_filter(
            self::classesIn(app_path('Jobs'), 'App\\Jobs'),
            fn (string $class) => is_subclass_of($class, ShouldQueue::class),
        ));
    }

    /**
     * The job class or command each scheduled event runs.
     *
     * @return list<string>
     */
    public static function scheduledEvents(): array
    {
        $names = [];

        foreach (app(Schedule::class)->events() as $event) {
            $names[] = $event instanceof CallbackEvent
                ? $event->description
                : self::commandName((string) $event->command);
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Every AI tool class, without the wrappers that guard or audit them.
     *
     * @return list<class-string>
     */
    public static function aiTools(): array
    {
        return array_values(array_filter(
            self::classesIn(app_path('Ai/Tools'), 'App\\Ai\\Tools'),
            fn (string $class) => is_subclass_of($class, Tool::class)
                && ! in_array($class, [GuardedTool::class, AuditedTool::class], true),
        ));
    }

    /**
     * "'/usr/bin/php' 'artisan' queue:check-waits" → "queue:check-waits"
     * (Windows quotes the paths with double quotes instead).
     */
    private static function commandName(string $command): string
    {
        return trim((string) preg_replace('/^.*["\']artisan["\']\s+/', '', $command));
    }

    /**
     * @return list<class-string>
     */
    private static function classesIn(string $directory, string $namespace): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = $namespace.'\\'.$relative;

            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
