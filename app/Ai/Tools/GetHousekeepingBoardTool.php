<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Models\Hotel;
use App\Services\HousekeepingService;
use App\Services\Reports\HousekeepingBoard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The housekeeping board for the Admin AI: the same rooms, counts and open
 * cleaning or inspection tasks the board screen shows (SPEC-030).
 */
class GetHousekeepingBoardTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'The housekeeping board right now: counts of rooms by housekeeping status (dirty, cleaning, clean, '
            .'inspected) and out of order, then each room with its housekeeping status, room status, readiness, the '
            .'in-house guest\'s departure date and its open cleaning or inspection task. Filter by housekeeping status, '
            .'room status, floor or building. Returns at most 50 rooms with the total.';
    }

    public function handle(Request $request): Stringable|string
    {
        $board = app(HousekeepingBoard::class)->for($this->hotel, [
            'housekeeping_status' => $request->string('housekeeping_status')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'floor' => $request->filled('floor') ? $request->string('floor')->toString() : null,
            'building' => $request->string('building')->toString() ?: null,
        ]);

        $housekeeping = app(HousekeepingService::class);
        $rooms = ListResult::collectionPayload($board['rows'], ListResult::limit($request), fn (array $row) => [
            'room_number' => $row['room']->room_number,
            'room_type' => $row['room']->roomType?->name,
            'floor' => $row['room']->floor,
            'building' => $row['room']->building,
            'housekeeping_status' => $row['room']->housekeeping_status,
            'status' => $row['room']->status,
            'ready' => $housekeeping->isReady($row['room'], $this->hotel),
            'departure_date' => $row['departure_date'],
            'open_task' => $row['open_task'] ? [
                'id' => $row['open_task']->id,
                'kind' => $row['open_task']->housekeeping_kind,
                'status' => $row['open_task']->status,
                'assigned_to' => $row['open_task']->assignedToUser?->name ?? $row['open_task']->assignedToTeam?->name,
            ] : null,
        ]);

        return json_encode([
            'date' => $board['date'],
            'inspection_required' => $board['inspection_required'],
            'counts' => $board['counts'],
            ...$rooms,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'housekeeping_status' => $schema->string()->enum(['dirty', 'cleaning', 'clean', 'inspected']),
            'status' => $schema->string()->enum(['available', 'occupied', 'out_of_order']),
            'floor' => $schema->string(),
            'building' => $schema->string(),
            'limit' => $schema->integer()->description('At most this many rooms, 1-50. Default 50.'),
        ];
    }
}
