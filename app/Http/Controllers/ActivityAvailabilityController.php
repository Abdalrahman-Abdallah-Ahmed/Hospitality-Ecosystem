<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityAvailabilityRequest;
use App\Models\Activity;
use App\Services\ActivityAvailabilityService;
use App\Support\Activities\DayAvailability;
use Illuminate\Http\JsonResponse;

class ActivityAvailabilityController extends Controller
{
    public function __construct(private readonly ActivityAvailabilityService $availability) {}

    /**
     * Whether the activity runs, and how many places it has left, on each
     * date of the range: the data behind the booking calendar (SPEC-041).
     * The same numbers the booking check uses, so a date shown with N places
     * takes a booking of N.
     */
    public function __invoke(ActivityAvailabilityRequest $request, Activity $activity): JsonResponse
    {
        $this->authorize('view', $activity);

        $days = $this->availability->range($activity, $request->validated('from'), $request->to());

        return apiResponse('Activity availability fetched successfully.', 200, [
            'activity_id' => $activity->id,
            'daily_capacity' => $activity->daily_capacity,
            'duration_days' => $activity->duration_days,
            'days' => array_map(fn (DayAvailability $day) => $day->toArray(), $days),
        ]);
    }
}
