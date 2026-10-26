<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The full health check (spec 017 FR-026, contracts/health-api.md).
 *
 * The public GET /up only says the application boots. This one also proves
 * the database, the queue and the cache answer, so it is kept behind a
 * secret token held by the deploy and the uptime monitor. It never says
 * why a check failed: the failure goes to error tracking, not the body.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = (string) config('health.token');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Health-Token'))) {
            return apiResponse('Unauthorized.', 401);
        }

        $checks = [
            'database' => $this->passes(fn () => DB::select('select 1')),
            'queue' => $this->passes(fn () => $this->readQueue()),
            'cache' => $this->passes(fn () => $this->roundTripCache()),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return apiResponse(
            $healthy ? 'Healthy.' : 'Unhealthy.',
            $healthy ? 200 : 503,
            ['status' => $healthy ? 'healthy' : 'unhealthy', 'checks' => $checks],
        );
    }

    private function passes(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    /**
     * Reads the jobs table through the queue's own connection, so the check
     * follows the queue wherever it is configured to live.
     */
    private function readQueue(): void
    {
        $queue = config('queue.connections.database');

        DB::connection($queue['connection'] ?? null)->table($queue['table'] ?? 'jobs')->limit(1)->get();
    }

    private function roundTripCache(): void
    {
        $key = 'health:'.Str::uuid();
        Cache::put($key, 'ok', 10);
        $value = Cache::get($key);
        Cache::forget($key);

        throw_unless($value === 'ok', new \RuntimeException('Cache did not return what was written.'));
    }
}
