<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Database\Seeder;

class HospitalitySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Hospitality Admin',
            'email' => 'admin@hospitality.test',
            'password' => bcrypt('123123123'),
            'role' => 'admin',
            'phone_number' => '201151793758',
        ]);

        // DatabaseSeeder runs WithoutModelEvents, so Hotel's creating hook —
        // which normally gives a group-less hotel a single-property group of
        // its own — does not fire here. The group is created explicitly
        // instead. The NOT NULL constraint on hotels.hotel_group_id is what
        // actually guarantees this, and it is the reason this was noticed.
        $group = HotelGroup::create([
            'name' => 'Grand Harbor Hotel',
            'slug' => 'grand-harbor-hotel',
            'country_code' => 'EG',
            'default_currency' => 'USD',
            'default_timezone' => 'Africa/Cairo',
        ]);

        $hotel = Hotel::create([
            'owner_id' => $user->id,
            'hotel_group_id' => $group->id,
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

        $user = $user->update([
            'hotel_id' => $hotel->id,
        ]);

        $categories = (new ActivityCategorySeeder)->seedFor($hotel);
        $category = $categories->firstWhere('slug', 'wellness-spa');

        $activities = [
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

        foreach ($activities as $activityData) {
            Activity::create([
                'hotel_id' => $hotel->id,
                'category_id' => $category->id,
                'name' => $activityData['name'],
                'description' => $activityData['description'],
                'price' => $activityData['price'],
                'currency' => $hotel->currency,
                'is_active' => true,
            ]);
        }
    }
}
