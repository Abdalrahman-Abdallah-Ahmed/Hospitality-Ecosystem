<?php

use App\Models\ReservationRoom;
use App\Services\Reservations\RoomAssignmentService;
use App\Support\Reservations\RoomHoldConflict;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;

/*
| Two people assigning one room to two bookings at the same moment (spec 020
| SC-001): exactly one wins. A second PHP process takes the room in its own
| transaction and holds it uncommitted; this process's assignment passes the
| rule check (it cannot see the other's row yet), reaches the database, waits
| on the constraint, and is refused once the other commits. That is the race
| the `reservation_rooms_no_overlap` constraint exists for.
|
| Both processes need committed data, so this file uses DatabaseTruncation.
*/

uses(DatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

/**
 * Starts a process that puts `$roomId` on `$lineId` inside a transaction,
 * prints "ready", waits, then commits.
 *
 * @return array{0: resource, 1: array<int, resource>}
 */
function racTakeRoomElsewhere(string $lineId, string $roomId): array
{
    $db = config('database.connections.'.config('database.default'));
    $script = <<<'PHP'
        $pdo = new PDO(getenv('RAC_DSN'), getenv('RAC_USER'), getenv('RAC_PASSWORD'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        $pdo->prepare('update reservation_rooms set room_id = ? where id = ?')->execute([getenv('RAC_ROOM'), getenv('RAC_LINE')]);
        echo "ready\n";
        fflush(STDOUT);
        usleep(400000);
        $pdo->commit();
        echo "committed\n";
        PHP;

    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
        ...getenv(),
        'RAC_DSN' => "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}",
        'RAC_USER' => $db['username'],
        'RAC_PASSWORD' => $db['password'],
        'RAC_ROOM' => $roomId,
        'RAC_LINE' => $lineId,
    ]);

    expect(trim((string) fgets($pipes[1])))->toBe('ready');

    return [$process, $pipes];
}

it('lets exactly one of two simultaneous assignments of one room win', function () {
    [$hotel, $type, [$room]] = fdHotel(3);

    for ($run = 0; $run < 20; $run++) {
        $nights = ['arrival_date' => now()->addDays(3 + $run * 3)->toDateString(), 'departure_date' => now()->addDays(5 + $run * 3)->toDateString()];
        $theirs = fdBook($hotel, $type, [null], $nights)->reservationRooms()->sole();
        $ours = fdBook($hotel, $type, [null], $nights);
        $ourLine = $ours->reservationRooms()->sole();

        [$process, $pipes] = racTakeRoomElsewhere($theirs->id, $room->id);

        try {
            app(RoomAssignmentService::class)->assign($ours, [['line_id' => $ourLine->id, 'room_id' => $room->id]]);
            $won = true;
        } catch (ValidationException $e) {
            // Refused by the database (the race), or by the rule check once
            // the other side committed: either way with a clear message.
            $won = false;
            $message = collect($e->errors())->flatten()->first();
            expect($message === RoomHoldConflict::MESSAGE || str_contains($message, 'on overlapping nights'))->toBeTrue();
        }

        expect(trim((string) stream_get_contents($pipes[1])))->toBe('committed');
        proc_close($process);

        expect($won)->toBeFalse()
            ->and(ReservationRoom::whereKey($theirs->id)->value('room_id'))->toBe($room->id)
            ->and($ourLine->fresh()->room_id)->toBeNull();
    }
});
