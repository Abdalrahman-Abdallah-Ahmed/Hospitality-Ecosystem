<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomTypeSeeder extends Seeder
{
    public function run(): void
    {
        $hotels = Hotel::all();

        foreach ($hotels as $hotel) {
            RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Standard Room'],
                [
                    'description' => 'Comfortable room for individual travelers',
                    'max_occupancy' => 2,
                    'adult_capacity' => 1,
                    'child_capacity' => 1,
                    'bed_configuration' => ['beds' => [['type' => 'twin', 'count' => 1]]],
                    'amenities' => ['WiFi', 'AC', 'TV'],
                    'base_price' => 80.00,
                    'is_active' => true,
                ]
            );

            RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Double Room'],
                [
                    'description' => 'Spacious room with queen bed',
                    'max_occupancy' => 2,
                    'adult_capacity' => 2,
                    'child_capacity' => 0,
                    'bed_configuration' => ['beds' => [['type' => 'queen', 'count' => 1]]],
                    'amenities' => ['WiFi', 'AC', 'TV', 'Mini Bar'],
                    'base_price' => 120.00,
                    'is_active' => true,
                ]
            );

            RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Deluxe Suite'],
                [
                    'description' => 'Luxurious suite with city view',
                    'max_occupancy' => 4,
                    'adult_capacity' => 2,
                    'child_capacity' => 2,
                    'bed_configuration' => ['beds' => [['type' => 'king', 'count' => 1], ['type' => 'twin', 'count' => 1]]],
                    'amenities' => ['WiFi', 'AC', 'TV', 'Mini Bar', 'Bathrobe', 'Jacuzzi'],
                    'base_price' => 200.00,
                    'is_active' => true,
                ]
            );

            RoomType::firstOrCreate(
                ['hotel_id' => $hotel->id, 'name' => 'Family Room'],
                [
                    'description' => 'Spacious room for families',
                    'max_occupancy' => 4,
                    'adult_capacity' => 2,
                    'child_capacity' => 2,
                    'bed_configuration' => ['beds' => [['type' => 'queen', 'count' => 1], ['type' => 'twin', 'count' => 1]]],
                    'amenities' => ['WiFi', 'AC', 'TV', 'Game Console', 'Kitchenette'],
                    'base_price' => 150.00,
                    'is_active' => true,
                ]
            );
        }
    }
}
