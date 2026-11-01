<?php

namespace App\Console\Commands;

use App\Support\Reservations\RoomAssignmentRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Lists every room held by two bookings on a shared night (spec 020 FR-012).
 *
 * Run before the migration that adds `reservation_rooms_no_overlap`: it
 * refuses to run while any exist, and staff resolve them by hand; nothing is
 * changed automatically. After it, the list stays empty by construction.
 */
class RoomOverlapsCommand extends Command
{
    protected $signature = 'rooms:overlaps';

    protected $description = 'List rooms held by two bookings on overlapping nights';

    public function handle(): int
    {
        // Across every hotel on purpose: a platform-owner report, read-only.
        $overlaps = TenantContext::withoutScope(fn () => RoomAssignmentRules::overlaps());

        if ($overlaps->isEmpty()) {
            $this->info('No overlapping room assignments.');

            return self::SUCCESS;
        }

        $this->table(
            ['Hotel', 'Room', 'Booking', 'Booking', 'From', 'To'],
            $overlaps->map(fn ($row) => [$row->hotel, $row->room_number, $row->first, $row->second, $row->from, $row->to])->all(),
        );
        $this->error("{$overlaps->count()} overlapping room assignments; resolve them before migrating.");

        return self::FAILURE;
    }
}
