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
        return [
            'hotel_id' => Hotel::factory(),
            'room_type_id' => fn (array $attributes) => RoomType::factory()->create(['hotel_id' => $attributes['hotel_id']])->id,
            'room_number' => $this->faker->unique()->numerify('###'),
            'floor' => $this->faker->numberBetween(1, 10),
            'status' => 'available',
            'housekeeping_status' => 'clean',
        ];
    }

    public function outOfOrder(string $reason = 'Test'): static
    {
        return $this->state(fn () => [
            'status' => 'out_of_order',
            'out_of_order_reason' => $reason,
            'out_of_order_since' => now(),
        ]);
    }

    public function dirty(): static
    {
        return $this->state(fn () => ['housekeeping_status' => 'dirty']);
    }
}
