<?php

namespace App\Console\Commands;

use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Knowledge\ChunkSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the data volume the performance targets are measured at (spec 017
 * SC-005, research R7): by default 100 hotels, each with three years of
 * reservations (about 15,000), their room lines and stays, activity
 * bookings and 200 knowledge passages.
 *
 * Writes in bulk, bypassing model events: this is measurement data, not
 * business data. It refuses any database whose name does not end in _bench,
 * so it can never touch the development or test database.
 */
class SeedBenchmarkVolumeCommand extends Command
{
    protected $signature = 'hardening:seed-volume
        {--hotels=100 : How many hotels to create}
        {--years=3 : How many years of reservations each hotel gets}
        {--rooms=60 : Rooms per hotel}';

    protected $description = 'Seed the SC-005 benchmark volume into a *_bench database';

    private const BATCH = 1000;

    private const ROOM_TYPES = 4;

    private const ACTIVITIES = 5;

    private const KNOWLEDGE_PASSAGES = 200;

    private int $bookingNumber = 0;

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_bench')) {
            $this->error("Refusing to seed \"{$database}\": the database name must end in _bench.");

            return self::FAILURE;
        }

        $hotels = (int) $this->option('hotels');
        $years = (int) $this->option('years');
        $rooms = (int) $this->option('rooms');

        $bar = $this->output->createProgressBar($hotels);
        // Continue after any earlier run instead of reusing its references.
        $this->bookingNumber = (int) DB::table('bookings')->count();

        foreach (range(1, $hotels) as $number) {
            DB::transaction(fn () => $this->seedHotel($number, $years, $rooms));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Reservations: '.DB::table('reservations')->count().', bookings: '.DB::table('bookings')->count().', passages: '.DB::table('knowledge_chunks')->count());

        return self::SUCCESS;
    }

    private function seedHotel(int $number, int $years, int $roomCount): void
    {
        $owner = User::factory()->role(UserRole::ADMIN)->create(['email' => "bench-owner-{$number}-".Str::random(6).'@example.test']);
        $hotel = Hotel::create([
            'owner_id' => $owner->id,
            'name' => "Bench Hotel {$number}",
            'slug' => "bench-hotel-{$number}-".Str::random(6),
            'currency' => 'USD',
        ]);
        $owner->update(['hotel_id' => $hotel->id]);
        $now = now();

        $types = [];
        foreach (range(1, self::ROOM_TYPES) as $t) {
            $types[] = $this->insert('room_types', [
                'hotel_id' => $hotel->id, 'name' => "Type {$t}", 'max_occupancy' => 3, 'adult_capacity' => 2,
                'child_capacity' => 1, 'base_price' => 100 * $t, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $rooms = [];
        foreach (range(1, $roomCount) as $r) {
            $rooms[] = ['id' => $this->insert('rooms', [
                'hotel_id' => $hotel->id, 'room_type_id' => $types[$r % self::ROOM_TYPES], 'room_number' => (string) (100 + $r),
                'status' => 'available', 'housekeeping_status' => 'clean', 'created_at' => $now, 'updated_at' => $now,
            ]), 'type' => $types[$r % self::ROOM_TYPES]];
        }

        $activities = [];
        foreach (range(1, self::ACTIVITIES) as $a) {
            $activities[] = $this->insert('activities', [
                'hotel_id' => $hotel->id, 'name' => "Activity {$a}", 'price' => 50, 'currency' => 'USD',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $guests = [];
        foreach (range(1, 4000) as $g) {
            $guests[] = ['id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'first_name' => "Guest{$g}", 'last_name' => 'Bench',
                'phone_number' => '+2010'.str_pad((string) ($number * 10000 + $g), 8, '0', STR_PAD_LEFT),
                'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulk('guests', $guests);

        $this->seedReservations($hotel, $rooms, $guests, $activities, $years);
        $this->seedKnowledge($hotel);
    }

    /**
     * Each room gets back-to-back stays from $years ago to 90 days ahead, so
     * no two live lines ever hold one room on the same night.
     */
    private function seedReservations(Hotel $hotel, array $rooms, array $guests, array $activities, int $years): void
    {
        $today = CarbonImmutable::now()->startOfDay();
        $end = $today->addDays(90);
        $reservations = $lines = $stays = $bookings = [];
        $sequence = 0;

        foreach ($rooms as $room) {
            $start = $today->subYears($years)->addDays(random_int(0, 3));

            while ($start < $end) {
                $nights = random_int(1, 5);
                $departure = $start->addDays($nights);
                $cancelled = random_int(1, 10) === 1;
                $guest = $guests[array_rand($guests)]['id'];
                $reservationId = (string) Str::uuid();
                $lineId = (string) Str::uuid();
                $sequence++;

                [$reservationStatus, $stayStatus] = match (true) {
                    $cancelled => [ReservationStatus::CANCELLED, StayStatus::CANCELLED],
                    $departure <= $today => [ReservationStatus::CHECKED_OUT, StayStatus::DEPARTED],
                    $start <= $today => [ReservationStatus::CHECKED_IN, StayStatus::IN_HOUSE],
                    default => [ReservationStatus::CONFIRMED, StayStatus::EXPECTED],
                };

                $reservations[] = [
                    'id' => $reservationId, 'hotel_id' => $hotel->id, 'guest_id' => $guest,
                    'reservation_id' => 'BENCH-'.$sequence, 'arrival_date' => $start->toDateString(),
                    'departure_date' => $departure->toDateString(), 'status' => $reservationStatus->value,
                    'adults' => 2, 'children' => 0, 'reservation_value' => 100 * $nights, 'currency' => 'USD',
                    'created_at' => $start->subDays(20), 'updated_at' => $start,
                ];
                $lines[] = [
                    'id' => $lineId, 'hotel_id' => $hotel->id, 'reservation_id' => $reservationId,
                    'room_type_id' => $room['type'], 'room_id' => $cancelled ? null : $room['id'],
                    'status' => $cancelled ? ReservationRoomStatus::CANCELLED->value : ReservationRoomStatus::RESERVED->value,
                    'created_at' => $start->subDays(20), 'updated_at' => $start,
                ];
                $stays[] = [
                    'id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'guest_id' => $guest,
                    'reservation_id' => $reservationId, 'reservation_room_id' => $lineId,
                    'room_id' => $cancelled ? null : $room['id'], 'status' => $stayStatus->value,
                    'planned_arrival_date' => $start->toDateString(), 'planned_departure_date' => $departure->toDateString(),
                    'nights' => $nights, 'adults' => 2, 'children' => 0, 'created_at' => $start->subDays(20), 'updated_at' => $start,
                ];

                if (! $cancelled && random_int(1, 3) === 1) {
                    $bookings[] = [
                        'id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'guest_id' => $guest,
                        'reservation_id' => $reservationId, 'activity_id' => $activities[array_rand($activities)],
                        // Sequential, so 600,000 references never collide (8 characters max).
                        'reference' => 'R'.str_pad(strtoupper(base_convert((string) ++$this->bookingNumber, 10, 36)), 7, '0', STR_PAD_LEFT), 'item_name' => 'Activity',
                        'status' => $departure <= $today ? 'realised' : 'confirmed', 'pax' => 2,
                        'scheduled_date' => $start->toDateString(), 'charge_model' => 'pay_on_site',
                        'expected_value' => 100, 'currency' => 'USD', 'origin' => 'staff',
                        'created_at' => $start->subDays(5), 'updated_at' => $start,
                    ];
                }

                $start = $departure->addDays(random_int(0, 3));
            }
        }

        $this->bulk('reservations', $reservations);
        $this->bulk('reservation_rooms', $lines);
        $this->bulk('stays', $stays);
        $this->bulk('bookings', $bookings);
    }

    /**
     * Passages with random stored embeddings: enough for the vector index to
     * be measured, without any provider call.
     */
    private function seedKnowledge(Hotel $hotel): void
    {
        $now = now();
        $rows = [];

        foreach (range(1, self::KNOWLEDGE_PASSAGES) as $k) {
            $vector = array_map(fn () => mt_rand() / mt_getrandmax() - 0.5, range(1, ChunkSynchronizer::DIMENSIONS));
            $rows[] = [
                'id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'chunkable_type' => 'knowledge_base_article',
                'chunkable_id' => (string) Str::uuid(), 'category' => 'policy', 'content' => "Bench passage {$k}",
                'embedding' => '['.implode(',', $vector).']', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $this->bulk('knowledge_chunks', $rows, 200);
    }

    private function insert(string $table, array $row): string
    {
        $row['id'] = (string) Str::uuid();
        DB::table($table)->insert($row);

        return $row['id'];
    }

    private function bulk(string $table, array $rows, int $size = self::BATCH): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
