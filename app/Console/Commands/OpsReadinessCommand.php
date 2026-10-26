<?php

namespace App\Console\Commands;

use App\Enums\QueueLane;
use App\Services\AiCost\AiPriceCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the production configuration before go-live and after any change to
 * it, naming each problem instead of letting it surface on the first guest
 * message (spec 017 FR-032, contracts/operations-interfaces.md).
 *
 * Prints variable names, never their values.
 */
class OpsReadinessCommand extends Command
{
    protected $signature = 'ops:readiness
        {--deploy : Skip the lane wait check, for use right after workers restart}';

    protected $description = 'Check that the production configuration is complete';

    /**
     * Providers the agents rely on, by config key: text and embeddings.
     */
    private const PROVIDER_KEYS = ['ai.default', 'ai.default_for_embeddings'];

    public function handle(): int
    {
        $checks = [
            'app.env' => $this->appEnvironment(...),
            'app.secrets' => fn () => $this->missing([
                'API_KEY' => config('app.api_key'),
                'WHATSAPP_VERIFY_TOKEN' => config('services.whatsapp.verify_token'),
                'WHATSAPP_APP_SECRET' => config('services.whatsapp.app_secret'),
            ]),
            'ai.provider' => $this->aiProviders(...),
            'ai.models' => $this->aiPrices(...),
            'ai.ceilings' => fn () => (float) config('ai_cost.daily_ceiling_usd') > 0 ? null : 'AI_COST_DAILY_CEILING_USD must be above 0',
            'infra.queue' => fn () => $this->reachable(fn () => DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table', 'jobs'))->limit(1)->get()),
            'infra.cache' => fn () => $this->reachable(function () {
                $key = 'readiness:'.Str::uuid();
                Cache::put($key, true, 10);
                throw_unless(Cache::pull($key) === true, new \RuntimeException('cache round trip failed'));
            }),
            'infra.lanes' => $this->lanes(...),
            'ops.health_token' => fn () => strlen((string) config('health.token')) >= 32 ? null : 'HEALTH_CHECK_TOKEN must be at least 32 characters',
            'ops.sentry' => fn () => filled(config('sentry.dsn')) ? null : 'SENTRY_LARAVEL_DSN is not set',
            'ops.alerts' => fn () => match (true) {
                blank(config('ops.alert_email')) => 'OPS_ALERT_EMAIL is not set',
                in_array(config('mail.default'), ['log', 'array'], true) => 'MAIL_MAILER does not deliver mail',
                default => null,
            },
        ];

        $failed = 0;

        foreach ($checks as $name => $check) {
            if ($name === 'infra.lanes' && $this->option('deploy')) {
                $this->line("- {$name}: skipped (--deploy)");

                continue;
            }

            $problem = $check();

            if ($problem === null) {
                $this->line("✓ {$name}");
            } else {
                $failed++;
                $this->line("<error>✗ {$name}: {$problem}</error>");
            }
        }

        $failed === 0
            ? $this->info('Ready.')
            : $this->error("{$failed} check(s) failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function appEnvironment(): ?string
    {
        return match (true) {
            ! app()->environment('production') => 'APP_ENV is not production',
            (bool) config('app.debug') => 'APP_DEBUG is on',
            blank(config('app.key')) => 'APP_KEY is not set',
            default => null,
        };
    }

    private function aiProviders(): ?string
    {
        $missing = [];

        foreach (self::PROVIDER_KEYS as $key) {
            $provider = (string) config($key);

            if (blank(config("ai.providers.{$provider}.key"))) {
                $missing[] = "the {$provider} key ({$key})";
            }
        }

        return $missing === [] ? null : 'No API key for '.implode(', ', array_unique($missing));
    }

    private function aiPrices(): ?string
    {
        $providers = array_map(fn (string $key) => (string) config($key), self::PROVIDER_KEYS);
        $unpriced = app(AiPriceCoverage::class)->unpriced($providers);

        return $unpriced === [] ? null : 'No model prices for '.implode(', ', $unpriced).': cost cannot be attributed';
    }

    private function lanes(): ?string
    {
        try {
            $waits = CheckQueueWaitsCommand::waits();
        } catch (Throwable) {
            return 'not checked: the queue is not reachable';
        }

        $late = $waits
            ->filter(fn (array $wait, string $lane) => $wait['seconds'] > QueueLane::from($lane)->waitLimitSeconds())
            ->keys();

        return $late->isEmpty() ? null : 'Work waiting past its limit in: '.$late->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function missing(array $values): ?string
    {
        $missing = array_keys(array_filter($values, fn ($value) => blank($value)));

        return $missing === [] ? null : implode(', ', $missing).' not set';
    }

    private function reachable(callable $probe): ?string
    {
        try {
            $probe();

            return null;
        } catch (Throwable) {
            return 'not reachable';
        }
    }
}
