<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesFrontDeskActions;
use App\Http\Requests\CheckInRequest;
use App\Http\Requests\CheckOutRequest;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\NoShowRequest;
use App\Http\Requests\StayDayRequest;
use App\Http\Resources\StayResource;
use App\Models\Stay;
use App\Services\NoShowService;
use App\Services\StayLifecycleService;
use App\Support\RequestRules\GenericQuery;
use App\Support\Stays\FrontDeskLists;
use Illuminate\Http\JsonResponse;

/**
 * Stays, the front desk's daily lists (FR-016, FR-017), and the actions on
 * one room: check-in (SPEC-024), check-out (SPEC-025) and no-shows (SPEC-012).
 * The whole-reservation forms live in ReservationController.
 */
class StayController extends Controller
{
    use HandlesFrontDeskActions;

    public function __construct(
        private readonly StayLifecycleService $lifecycle,
        private readonly NoShowService $noShows,
    ) {}

    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Stay::class);

        $query = Stay::with(['guest', 'reservation', 'reservationRoom.roomType', 'room']);

        return apiResponse('Stays fetched successfully.', 200, StayResource::collection(GenericQuery::apply($query, $request)));
    }

    public function show(Stay $stay): JsonResponse
    {
        $this->authorize('view', $stay);

        return apiResponse('Stay fetched successfully.', 200, StayResource::make($stay->load(['guest', 'reservation', 'reservationRoom.roomType', 'room'])));
    }

    /**
     * Checks one room in; `room_id` names a room for a line that has none
     * (FR-007a).
     */
    public function checkIn(CheckInRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('checkIn', $stay);

        $roomId = $request->validated('room_id');

        return $this->checkedIn($this->lifecycle->checkIn(
            $stay,
            $roomId ? strtolower($roomId) : null,
            $request->validated('checked_in_at'),
        ));
    }

    /**
     * Checks one room out; it gets one cleaning task.
     */
    public function checkOut(CheckOutRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('checkOut', $stay);

        return $this->checkedOut($this->lifecycle->checkOut($stay, $request->validated('checked_out_at')));
    }

    /**
     * One room never arrived (SPEC-012 FR-006). Authorized against the room's
     * reservation.
     */
    public function noShow(NoShowRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('noShow', $stay->reservationOrFail());

        return $this->noShowResult('Marked as a no-show.', $this->noShows->markStay($stay, $request->validated('reason')));
    }

    /**
     * One no-show room waits again, while its reservation is open.
     */
    public function undoNoShow(NoShowRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('noShow', $stay->reservationOrFail());

        return $this->noShowResult('No-show undone.', $this->noShows->undoStay(
            $stay,
            $request->validated('reason'),
            $this->overbookOverride($request),
        ));
    }

    public function arrivals(StayDayRequest $request): JsonResponse
    {
        return $this->list($request, 'arrivals');
    }

    public function departures(StayDayRequest $request): JsonResponse
    {
        return $this->list($request, 'departures');
    }

    public function inHouse(StayDayRequest $request): JsonResponse
    {
        return $this->list($request, 'in_house');
    }

    private function list(StayDayRequest $request, string $list): JsonResponse
    {
        $this->authorize('viewAny', Stay::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $day = $request->validated('date') ?? FrontDeskLists::today($hotel);

        return apiResponse('Stays fetched successfully.', 200, [
            'date' => $day,
            'stays' => StayResource::forDay(FrontDeskLists::get($hotel, $list, $day), $day),
        ]);
    }
}
