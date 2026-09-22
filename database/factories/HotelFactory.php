<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'hotel_group_id' => HotelGroup::factory(),
            'name' => $this->faker->company().' Hotel',
            'slug' => $this->faker->unique()->slug(),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'country_code' => 'US',
            'city' => $this->faker->city(),
            'address' => $this->faker->address(),
            'whatsapp_number' => $this->faker->numerify('##########'),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'branding' => [],
            'ai_preferences' => [],
            'is_active' => true,
        ];
    }
}
