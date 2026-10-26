<?php

namespace App\Console\Commands;

use App\Ai\Tools\KnowledgeSearchTool;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\RequestRules\GenericQuery;
use App\Support\Reservations\RoomAssignmentRules;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Times the four lookups the performance targets name (spec 017 SC-005,
 * research R7) against the volume hardening:seed-volume builds: availability
 * over 30 nights, the room-assignment overlap check, one bookings index page
 * and knowledge search. Fails when any 95th percentile reaches 1 second.
 *
 * Read-only, and like the seeder it only runs on a *_bench database.
 */
class RunBenchmarkCommand extends Command
{
    protected $signature = 'hardening:benchmark
        {--runs=200 : Timed runs per operation}
        {--explain : Print EXPLAIN (ANALYZE, BUFFERS) for each operation\'s slowest run}';

    protected $description = 'Time the SC-005 lookups and fail when a p95 reaches 1 second';

    private const TARGET_MS = 1000;

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_bench')) {
            $this->error("Refusing to run on \"{$database}\": the database name must end in _bench.");

            return self::FAILURE;
        }

        $hotelIds = Hotel::query()->pluck('id')->all();

        if ($hotelIds === []) {
            $this->error('No hotels: run hardening:seed-volume first.');

            return self::FAILURE;
        }

        // Knowledge search embeds the question first; a stored-style vector
        // keeps the provider out of the measurement.
        Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), array_map(
            fn () => mt_rand() / mt_getrandmax() - 0.5,
            range(1, ChunkSynchronizer::DIMENSIONS),
        )));

        $operations = [
            'availability, 30 nights' => function (Hotel $hotel) {
                $arrival = now()->addDays(random_int(-60, 60));

                return app(AvailabilityService::class)
                    ->forHotel($hotel, $arrival->toDateString(), $arrival->copy()->addDays(30)->toDateString());
            },
            'room assignment overlap' => fn (Hotel $hotel) => $this->overlapCheck($hotel),
            'bookings index page' => fn (Hotel $hotel) => GenericQuery::apply(
                Booking::query(),
                GenericIndexRequest::create('/api/booking', 'GET', ['sort' => '-created_at']),
            ),
            'knowledge search' => fn (Hotel $hotel) => (new KnowledgeSearchTool($hotel))->search('Is late checkout free?'),
        ];

        $runs = (int) $this->option('runs');
        $rows = [];
        $failed = false;

        foreach ($operations as $name => $operation) {
            [$timings, $slowest] = $this->time($operation, $hotelIds, $runs);
            sort($timings);
            $p50 = $timings[(int) floor(0.50 * (count($timings) - 1))];
            $p95 = $timings[(int) floor(0.95 * (count($timings) - 1))];
            $ok = $p95 < self::TARGET_MS;
            $failed = $failed || ! $ok;
            $rows[] = [$name, number_format($p50, 1), number_format($p95, 1), $ok ? 'ok' : 'OVER TARGET'];

            if ($this->option('explain') && $slowest !== null) {
                $this->explain($name, $slowest);
            }
        }

        $this->table(['Operation', 'p50 ms', 'p95 ms', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: list<float>, 1: ?array{sql: string, bindings: array}}
     */
    private function time(Closure $operation, array $hotelIds, int $runs): array
    {
        $timings = [];
        $slowest = null;
        $slowestMs = -1.0;

        foreach (range(1, $runs) as $run) {
            $hotel = Hotel::findOrFail($hotelIds[array_rand($hotelIds)]);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            TenantContext::runForHotel($hotel->id, fn () => $operation($hotel));
            $ms = (hrtime(true) - $started) / 1e6;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $timings[] = $ms;

            // Only the operation's own reads: never the usage log writes, nor
            // the schema lookups a first query makes.
            $reads = collect($queries)->filter(fn (array $query) => str_starts_with(strtolower(ltrim($query['query'])), 'select')
                && ! str_contains($query['query'], 'pg_'));

            if ($ms > $slowestMs && $reads->isNotEmpty()) {
                $slowestMs = $ms;
                $longest = $reads->sortByDesc('time')->first();
                $slowest = ['sql' => $longest['query'], 'bindings' => $longest['bindings']];
            }
        }

        return [$timings, $slowest];
    }

    /**
     * The check that stops a physical room going on two lines for the same
     * nights, for a random live line and a room of its type.
     */
    private function overlapCheck(Hotel $hotel): ?string
    {
        $line = ReservationRoom::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('status', 'reserved')
            ->inRandomOrder()
            ->first();

        $room = Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('room_type_id', $line?->room_type_id)
            ->inRandomOrder()
            ->first();

        return $line ? RoomAssignmentRules::reason($line, $room) : null;
    }

    private function explain(string $name, array $query): void
    {
        $this->line("<comment>{$name}: slowest query</comment>");

        foreach (DB::select('explain (analyze, buffers) '.$query['sql'], $query['bindings']) as $row) {
            $this->line('  '.((array) $row)['QUERY PLAN']);
        }
    }
}
