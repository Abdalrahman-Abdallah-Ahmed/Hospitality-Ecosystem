<?php

namespace App\Http\Controllers;

use App\Http\Requests\HousekeepingBoardRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\Reports\HousekeepingBoard;
use Illuminate\Http\JsonResponse;

/**
 * The housekeeping board (SPEC-030, FR-030): every room with its
 * housekeeping and room status, readiness, the in-house guest's departure
 * and its open cleaning or inspection task. The query lives in
 * HousekeepingBoard, shared with the Admin AI.
 */
class HousekeepingBoardController extends Controller
{
    public function __invoke(HousekeepingBoardRequest $request, HousekeepingBoard $board): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $result = $board->for($hotel, [
            'housekeeping_status' => $request->filled('housekeeping_status') ? $request->validated('housekeeping_status') : null,
            'status' => $request->filled('status') ? $request->validated('status') : null,
            'floor' => $request->filled('floor') ? $request->validated('floor') : null,
            'building' => $request->filled('building') ? $request->validated('building') : null,
            'team_id' => $request->validated('team_id'),
        ]);

        return apiResponse('Housekeeping board fetched successfully.', 200, [
            'date' => $result['date'],
            'inspection_required' => $result['inspection_required'],
            'counts' => $result['counts'],
            'rooms' => $result['rows']->map(fn (array $row) => [
                'room' => RoomResource::make($row['room'])->resolve(),
                'departure_date' => $row['departure_date'],
                'open_task' => $row['open_task'] ? [
                    'id' => $row['open_task']->id,
                    'housekeeping_kind' => $row['open_task']->housekeeping_kind,
                    'cleaning_reason' => $row['open_task']->cleaning_reason,
                    'status' => $row['open_task']->status,
                    'assigned_to_team_id' => $row['open_task']->assigned_to_team_id,
                    'assigned_to_user_id' => $row['open_task']->assigned_to_user_id,
                ] : null,
            ])->values(),
        ]);
    }
}
