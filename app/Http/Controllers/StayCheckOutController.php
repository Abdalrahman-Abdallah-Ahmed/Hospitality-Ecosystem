<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckOutRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\StayResource;
use App\Http\Resources\TaskResource;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\StayLifecycleService;
use Illuminate\Http\JsonResponse;

/**
 * Front-desk check-out (SPEC-025): one room, or every room of a reservation
 * still in the house. Each room checked out gets one cleaning task.
 */
class StayCheckOutController extends Controller
{
    public function __construct(private readonly StayLifecycleService $lifecycle) {}

    public function stay(CheckOutRequest $request, Stay $stay): JsonResponse
    {
        $this->authorize('checkOut', $stay);

        return $this->respond($this->lifecycle->checkOut($stay, $request->validated('checked_out_at')));
    }

    public function reservation(CheckOutRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('checkOut', [Stay::class, $reservation]);

        return $this->respond($this->lifecycle->checkOutReservation($reservation, $request->validated('checked_out_at')));
    }

    /**
     * @param  array{reservation: Reservation, stays: iterable<Stay>, cleaning_tasks: iterable}  $result
     */
    private function respond(array $result): JsonResponse
    {
        return apiResponse('Checked out.', 200, [
            'reservation' => ReservationResource::make($result['reservation']),
            'stays' => StayResource::collection($result['stays']),
            'cleaning_tasks' => TaskResource::collection($result['cleaning_tasks']),
        ]);
    }
}
