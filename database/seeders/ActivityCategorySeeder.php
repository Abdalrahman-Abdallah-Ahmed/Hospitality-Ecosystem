<?php

namespace Database\Seeders;

use App\Models\ActivityCategory;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

class ActivityCategorySeeder extends Seeder
{
    /**
     * Standalone entry point (`php artisan db:seed --class=ActivityCategorySeeder`).
     * Seeds the 10 categories onto the first hotel found.
     */
    public function run(): void
    {
        $hotel = Hotel::first();

        if (! $hotel) {
            return;
        }

        $this->seedFor($hotel);
    }

    /**
     * Create the 10 hotel/tourism/wellness activity categories for a given hotel.
     */
    public function seedFor(Hotel $hotel): Collection
    {
        $categories = [
            ['name' => 'Wellness & Spa', 'slug' => 'wellness-spa', 'description' => 'Massages, spa treatments, and relaxation sessions.'],
            ['name' => 'Adventure & Outdoor Tours', 'slug' => 'adventure-tours', 'description' => 'Desert safaris, hiking, and diving excursions.'],
            ['name' => 'Cultural & Heritage Excursions', 'slug' => 'cultural-excursions', 'description' => 'Museum visits and guided historical city tours.'],
            ['name' => 'Water Sports & Beach Activities', 'slug' => 'water-sports', 'description' => 'Snorkeling, jet skiing, and beach lounging.'],
            ['name' => 'Culinary Experiences', 'slug' => 'culinary-experiences', 'description' => 'Cooking classes, food tours, and chef\'s table dinners.'],
            ['name' => 'Nightlife & Entertainment', 'slug' => 'nightlife-entertainment', 'description' => 'Live shows, rooftop lounges, and evening entertainment.'],
            ['name' => 'Family & Kids Activities', 'slug' => 'family-kids', 'description' => 'Kids clubs and family-friendly excursions.'],
            ['name' => 'Transport & Transfers', 'slug' => 'transport-transfers', 'description' => 'Airport transfers and chauffeur services.'],
            ['name' => 'Shopping & Local Markets', 'slug' => 'shopping-markets', 'description' => 'Souvenir shopping and local bazaar tours.'],
            ['name' => 'Business & Events', 'slug' => 'business-events', 'description' => 'Conference rooms, meeting packages, and event planning.'],
        ];

        return new Collection(array_map(
            fn (array $category) => ActivityCategory::create([...$category, 'hotel_id' => $hotel->id]),
            $categories
        ));
    }
}
