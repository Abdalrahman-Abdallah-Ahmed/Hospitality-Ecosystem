<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\StayDayRequest;
use App\Http\Resources\StayResource;
use App\Models\Stay;
use App\Support\RequestRules\GenericQuery;
use App\Support\Stays\FrontDeskLists;
use Illuminate\Http\JsonResponse;

/**
 * Stays and the front desk's daily lists (FR-016, FR-017).
 */
class StayController extends Controller
{
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
