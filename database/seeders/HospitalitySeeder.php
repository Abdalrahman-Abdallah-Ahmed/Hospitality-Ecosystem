<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HospitalitySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Hospitality Admin',
            'email' => 'admin@hospitality.test',
            'password' => bcrypt('123123123'),
            'role' => 'admin',
            'phone_number' => '+201151793758',
        ]);

        $hotel = Hotel::create([
            'owner_id' => $user->id,
            'name' => 'Grand Harbor Hotel',
            'slug' => 'grand-harbor-hotel',
            'timezone' => 'Africa/Cairo',
            'currency' => 'USD',
            'country_code' => 'EG',
            'city' => 'Cairo',
            'address' => '123 Nile Avenue',
            'whatsapp_number' => '+201000000000',
            'email' => 'info@grandharbor.test',
            'phone' => '+20212345678',
            'branding' => [
                'primary_color' => '#1d4ed8',
                'secondary_color' => '#f59e0b',
            ],
            'ai_preferences' => [
                'tone' => 'warm',
                'priority' => 'revenue',
            ],
            'is_active' => true,
        ]);

        $category = ServiceCategory::create([
            'hotel_id' => $hotel->id,
            'name' => 'Wellness',
            'slug' => 'wellness',
            'description' => 'Spa and wellness experiences.',
        ]);

        $services = [
            ['name' => 'Spa Treatment', 'price' => 120, 'description' => 'Relaxing 60-minute massage.'],
            ['name' => 'Airport Transfer', 'price' => 35, 'description' => 'Private airport pickup.'],
            ['name' => 'Kids Club', 'price' => 25, 'description' => 'Fun activities for children.'],
            ['name' => 'Sunset Excursion', 'price' => 90, 'description' => 'Guided evening tour.'],
            ['name' => 'Breakfast Buffet', 'price' => 18, 'description' => 'Buffet breakfast for two.'],
            ['name' => 'Laundry Service', 'price' => 15, 'description' => 'Same-day laundry care.'],
            ['name' => 'Late Checkout', 'price' => 40, 'description' => 'Extended checkout until 2 PM.'],
            ['name' => 'Room Champagne', 'price' => 45, 'description' => 'Celebration amenity.'],
            ['name' => 'Yoga Session', 'price' => 30, 'description' => 'Morning private yoga.'],
            ['name' => 'Dinner Reservation', 'price' => 60, 'description' => 'Table reservation at rooftop restaurant.'],
        ];

        foreach ($services as $index => $serviceData) {
            Service::create([
                'hotel_id' => $hotel->id,
                'category_id' => $category->id,
                'name' => $serviceData['name'],
                'description' => $serviceData['description'],
                'price' => $serviceData['price'],
                'currency' => $hotel->currency,
                'is_active' => true,
                'availability' => [
                    'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                    'time_window' => '09:00-22:00',
                ],
                'reservation_rules' => [
                    'advance_hours' => 24,
                    'requires_confirmation' => true,
                ],
                'recommended_audiences' => ['couples', 'families', 'business'],
                'business_priority' => $index < 3 ? 'high' : 'medium',
                'ai_metadata' => [
                    'tagline' => $serviceData['name'],
                    'suggestion_reason' => 'Popular guest demand',
                ],
            ]);
        }
    }
}
