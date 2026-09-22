<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    public function definition(): array
    {
        $adults = $this->faker->numberBetween(1, 3);
        $children = $this->faker->numberBetween(0, 2);

        return [
            'hotel_id' => Hotel::factory(),
            'name' => $this->faker->unique()->word().' Room',
            'description' => $this->faker->sentence(),
            'max_occupancy' => $adults + $children,
            'adult_capacity' => $adults,
            'child_capacity' => $children,
            'bed_configuration' => null,
            'amenities' => null,
            'base_price' => $this->faker->randomFloat(2, 50, 500),
            'is_active' => true,
        ];
    }
}
