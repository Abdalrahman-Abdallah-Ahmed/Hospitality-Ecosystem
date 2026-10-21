<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\ReservationResource;
use App\Http\Resources\StayResource;
use App\Http\Resources\TaskResource;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the front-desk actions — check-in (SPEC-024), check-out (SPEC-025) and
 * no-shows (SPEC-012) — share between their reservation forms
 * (ReservationController) and their per-room forms (StayController): the
 * response bodies, so both answer in the same shape, and the overbook check.
 */
trait HandlesFrontDeskActions
{
    /**
     * Whether the request asks to save even if it oversells a room type. Asking
     * without reservations.overbook is refused before anything is written,
     * whether or not the booking would actually have oversold.
     */
    private function overbookOverride(Request $request): bool
    {
        if (! $request->boolean('overbook_override')) {
            return false;
        }

        $this->authorize('overbook', Reservation::class);

        return true;
    }

    /**
     * @param  array{reservation: Reservation, stays: iterable<Stay>, warnings: array}  $result
     */
    private function checkedIn(array $result): JsonResponse
    {
        return apiResponse('Checked in.', 200, [
            'reservation' => ReservationResource::make($result['reservation']),
            'stays' => StayResource::collection($result['stays']),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * @param  array{reservation: Reservation, stays: iterable<Stay>, cleaning_tasks: iterable}  $result
     */
    private function checkedOut(array $result): JsonResponse
    {
        return apiResponse('Checked out.', 200, [
            'reservation' => ReservationResource::make($result['reservation']),
            'stays' => StayResource::collection($result['stays']),
            'cleaning_tasks' => TaskResource::collection($result['cleaning_tasks']),
        ]);
    }

    /**
     * `warnings`: an undo whose room was given away meanwhile — the room was
     * taken off the line; staff assign another.
     *
     * @param  array{reservation: Reservation, stays: iterable<Stay>, warnings: list<string>}  $result
     */
    private function noShowResult(string $message, array $result): JsonResponse
    {
        return apiResponse($message, 200, [
            'reservation' => ReservationResource::make($result['reservation']),
            'stays' => StayResource::collection($result['stays']),
            'warnings' => $result['warnings'],
        ]);
    }
}
