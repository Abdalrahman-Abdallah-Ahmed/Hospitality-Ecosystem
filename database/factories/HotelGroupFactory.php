<?php

namespace Database\Factories;

use App\Models\HotelGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

class HotelGroupFactory extends Factory
{
    protected $model = HotelGroup::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(),
            'country_code' => 'US',
            'default_currency' => 'USD',
            'default_timezone' => 'UTC',
            'settings' => [],
            'is_active' => true,
            'contract_value_monthly' => 0,
            'contract_currency' => 'USD',
        ];
    }
}
