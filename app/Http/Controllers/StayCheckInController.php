<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\StayResource;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\StayLifecycleService;
use Illuminate\Http\JsonResponse;

/**
 * Front-desk check-in (SPEC-024): one room, or every room of a reservation.
 */
class StayCheckInController extends Controller
{
    public function __construct(private readonly StayLifecycleService $lifecycle) {}

    public function stay(CheckInRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('checkIn', $stay);

        $roomId = $request->validated('room_id');

        return $this->respond($this->lifecycle->checkIn(
            $stay,
            $roomId ? strtolower($roomId) : null,
            $request->validated('checked_in_at'),
        ));
    }

    public function reservation(CheckInRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('checkIn', [Stay::class, $reservation]);

        return $this->respond($this->lifecycle->checkInReservation(
            $reservation,
            $request->roomsByStayId(),
            $request->validated('checked_in_at'),
        ));
    }

    /**
     * @param  array{reservation: Reservation, stays: iterable<Stay>, warnings: array}  $result
     */
    private function respond(array $result): JsonResponse
    {
        return apiResponse('Checked in.', 200, [
            'reservation' => ReservationResource::make($result['reservation']),
            'stays' => StayResource::collection($result['stays']),
            'warnings' => $result['warnings'],
        ]);
    }
}
