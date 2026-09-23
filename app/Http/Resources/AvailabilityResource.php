<?php

namespace App\Http\Resources;

use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps AvailabilityService::forHotel(): one row per room type with its
 * nights, each night's counts, and the lowest sellable count over the range.
 */
class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'arrival_date' => $this->resource['arrival_date'],
            'departure_date' => $this->resource['departure_date'],
            'nights' => $this->resource['nights'],
            'room_types' => array_map(fn (array $row) => [
                'room_type' => self::roomType($row['room_type']),
                'bookable_for_stay' => $row['bookable_for_stay'],
                'nights' => $row['nights'],
            ], $this->resource['room_types']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function roomType(RoomType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'max_occupancy' => $type->max_occupancy,
            'adult_capacity' => $type->adult_capacity,
            'child_capacity' => $type->child_capacity,
            'is_active' => $type->is_active,
        ];
    }
}
