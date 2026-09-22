<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        $hotel = Hotel::factory();
        $roomType = RoomType::factory();

        return [
            'hotel_id' => $hotel,
            'room_type_id' => $roomType,
            'room_number' => $this->faker->unique()->numerify('###'),
            'floor' => $this->faker->numberBetween(1, 10),
            'status' => 'available',
            'housekeeping_status' => 'clean',
        ];
    }
}
