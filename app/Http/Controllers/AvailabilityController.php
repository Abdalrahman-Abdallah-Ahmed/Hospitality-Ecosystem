<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * How many rooms of each type the hotel can still sell, night by night,
     * for [arrival_date, departure_date).
     */
    public function index(AvailabilityRequest $request): JsonResponse
    {
        $this->authorize('viewAvailability', RoomType::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $roomTypeIds = $request->validated('room_type_ids');

        // One message whether the type is another hotel's, deleted or unknown,
        // so a lookup cannot be used to probe for other hotels' records.
        if ($roomTypeIds !== null && RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->whereIn('id', $roomTypeIds)
            ->count() !== count($roomTypeIds)) {
            return apiResponse('One or more of the selected room types are invalid.', 422);
        }

        $arrival = $request->validated('arrival_date');
        $departure = $request->validated('departure_date');

        $this->availability->assertRange($hotel, $arrival, $departure);

        return apiResponse(
            'Availability retrieved successfully.',
            200,
            AvailabilityResource::make($this->availability->forHotel($hotel, $arrival, $departure, $roomTypeIds)),
        );
    }
}
