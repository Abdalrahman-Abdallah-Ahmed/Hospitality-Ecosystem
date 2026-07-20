<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\GlopalIndexRequest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GlopalIndexRequest $request)
    {
        //
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read the uploaded file.',
            ]);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty.',
            ]);
        }

        $imported = 0;
        $headerMap = array_map(fn ($column) => strtolower(trim($column)), $header);

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row, fn ($value) => $value !== null && $value !== ''))) {
                continue;
            }

            $data = array_combine($headerMap, $row);
            if ($data === false) {
                continue;
            }

            $hotelName = trim((string) ($data['hotel_name'] ?? ''));
            $guestName = trim((string) ($data['guest_name'] ?? ''));
            $reservationId = trim((string) ($data['reservation_id'] ?? ''));

            if ($hotelName === '' || $guestName === '' || $reservationId === '') {
                continue;
            }

            $hotel = Hotel::firstOrCreate(
                ['slug' => Str::slug($hotelName)],
                [
                    'owner_id' => $request->user()?->id,
                    'name' => $hotelName,
                    'slug' => Str::slug($hotelName),
                    'currency' => $data['currency'] ?? 'USD',
                ]
            );

            $nameParts = preg_split('/\s+/', $guestName, 2) ?: [$guestName];
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $guest = Guest::firstOrCreate(
                [
                    'hotel_id' => $hotel->id,
                    'email' => trim((string) ($data['guest_email'] ?? '')),
                ],
                [
                    'hotel_id' => $hotel->id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]
            );

            $roomNumber = trim((string) ($data['room_number'] ?? ''));
            $room = null;
            if ($roomNumber !== '') {
                $room = Room::firstOrCreate(
                    [
                        'hotel_id' => $hotel->id,
                        'room_number' => $roomNumber,
                    ],
                    [
                        'hotel_id' => $hotel->id,
                        'room_number' => $roomNumber,
                        'room_type' => $data['room_type'] ?? null,
                        'floor' => $data['floor'] ?? null,
                        'status' => 'available',
                    ]
                );
            }

            Reservation::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'room_id' => $room?->id,
                'reservation_id' => $reservationId,
                'arrival_date' => $data['arrival_date'] ?? null,
                'departure_date' => $data['departure_date'] ?? null,
                'status' => $this->normalizeStatus($data['status'] ?? null),
                'adults' => (int) ($data['adults'] ?? 1),
                'children' => (int) ($data['children'] ?? 0),
                'source' => 'excel_import',
                'special_requests' => $data['special_requests'] ?? null,
                'booking_value' => (float) ($data['booking_value'] ?? 0),
                'currency' => $data['currency'] ?? $hotel->currency,
            ]);

            $imported++;
        }

        fclose($handle);

        return response()->json([
            'message' => 'Reservations imported successfully.',
            'imported' => $imported,
        ]);
    }

    protected function normalizeStatus(?string $status): string
    {
        $statusValue = strtolower(trim((string) $status));

        return match ($statusValue) {
            'confirmed', 'confirm', 'booked' => ReservationStatus::CONFIRMED->value,
            'checked_in', 'checkin', 'checked-in' => ReservationStatus::CHECKED_IN->value,
            'checked_out', 'checkout', 'checked-out' => ReservationStatus::CHECKED_OUT->value,
            'cancelled', 'canceled', 'cancel' => ReservationStatus::CANCELLED->value,
            default => ReservationStatus::PENDING->value,
        };
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        //
    }
}
