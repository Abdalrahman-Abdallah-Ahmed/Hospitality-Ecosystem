<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Database\Seeder;

class GuestReservationSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::firstOrFail();

        $guest = Guest::create([
            'hotel_id' => $hotel->id,
            'first_name' => 'Sara',
            'last_name' => 'Ahmed',
            'email' => 'sara.ahmed@example.test',
            'phone_number' => '201000000099',
            'preferred_language' => 'en',
            'nationality' => 'EG',
            'loyalty_status' => 'silver',
            'marketing_consent' => true,
        ]);

        Reservation::create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'RES-1001',
            'arrival_date' => now()->subDay(),
            'departure_date' => now()->addDays(3),
            'status' => ReservationStatus::CHECKED_IN,
            'adults' => 2,
            'children' => 0,
            'source' => 'whatsapp',
            'reservation_value' => 450,
            'currency' => $hotel->currency,
        ]);
    }
}
