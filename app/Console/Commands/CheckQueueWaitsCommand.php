<?php

namespace App\Console\Commands;

use App\Enums\QueueLane;
use App\Support\Observability\PlatformAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alerts the platform owner when a lane's oldest waiting job has waited past
 * that lane's limit (spec 017 FR-028, research R9).
 *
 * Runs on the scheduler, never as a queued job, so it is never stuck behind
 * the very work it is checking. Reads the database queue directly; when the
 * queue moves to Redis after the MVP, Horizon's own wait alerts replace this.
 */
class CheckQueueWaitsCommand extends Command
{
    protected $signature = 'queue:check-waits';

    protected $description = 'Alert when a queue lane has work waiting longer than its limit';

    public function handle(): int
    {
        foreach (self::waits() as $lane => $wait) {
            $limit = QueueLane::from($lane)->waitLimitSeconds();

            if ($wait['seconds'] <= $limit) {
                continue;
            }

            PlatformAlerts::send(
                "alert:lane-wait:{$lane}",
                (int) config('ops.lane_alert_cooldown_minutes'),
                "[Ops] Lane {$lane} waiting {$wait['seconds']}s",
                [
                    "The oldest job in the {$lane} lane has waited {$wait['seconds']} seconds; the limit is {$limit}.",
                    "Jobs waiting in this lane: {$wait['count']}.",
                    "Check its worker: systemctl status 'queue-worker@{$lane}-*'.",
                ],
            );

            $this->warn("{$lane}: oldest job waiting {$wait['seconds']}s (limit {$limit}s)");
        }

        return self::SUCCESS;
    }

    /**
     * The oldest unreserved wait and the job count per lane, for lanes that
     * have work waiting. Unknown queue names are ignored.
     *
     * @return Collection<string, array{seconds: int, count: int}>
     */
    public static function waits(): Collection
    {
        $connection = config('queue.connections.database');
        $now = now()->getTimestamp();
        $lanes = array_map(fn (QueueLane $lane) => $lane->value, QueueLane::cases());

        return DB::connection($connection['connection'] ?? null)
            ->table($connection['table'] ?? 'jobs')
            ->whereNull('reserved_at')
            ->whereIn('queue', $lanes)
            ->groupBy('queue')
            ->selectRaw('queue, min(available_at) as oldest, count(*) as waiting')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->queue => [
                'seconds' => max(0, $now - (int) $row->oldest),
                'count' => (int) $row->waiting,
            ]]);
    }
}
