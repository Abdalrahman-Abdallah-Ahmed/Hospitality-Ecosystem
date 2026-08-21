<?php

namespace App\Imports;

use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Throwable;

/**
 * Bulk-creates reservations (and their guests) from an uploaded spreadsheet,
 * scoped to a single hotel. A room_number with no matching room is created
 * rather than rejected, since imported data commonly predates the room
 * being entered into the system. Reuses the same guest-matching and
 * reservation-persistence rules as CreateReservationTool/ReservationController
 * so imported data behaves identically to any other entry point.
 */
class ReservationsImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    /** @var array<int, array{row: int, reason: string}> */
    public array $skipped = [];

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // +2: 1-based, plus the heading row consumed by WithHeadingRow.
            $rowNumber = $index + 2;

            try {
                $this->importRow($row, $rowNumber);
            } catch (Throwable $e) {
                $this->skipped[] = ['row' => $rowNumber, 'reason' => $e->getMessage()];
            }
        }
    }

    private function importRow(Collection $row, int $rowNumber): void
    {
        $phone = trim((string) ($row['guest_phone'] ?? ''));
        $arrivalDate = trim((string) ($row['arrival_date'] ?? ''));
        $departureDate = trim((string) ($row['departure_date'] ?? ''));

        if ($phone === '' || $arrivalDate === '' || $departureDate === '') {
            $this->skipped[] = [
                'row' => $rowNumber,
                'reason' => 'Missing required field(s): guest_phone, arrival_date, or departure_date.',
            ];

            return;
        }

        $room = null;
        $roomNumber = trim((string) ($row['room_number'] ?? ''));

        if ($roomNumber !== '') {
            $room = Room::firstOrCreate(
                ['hotel_id' => $this->hotel->id, 'room_number' => $roomNumber],
                [
                    'room_type' => trim((string) ($row['room_type'] ?? '')) ?: null,
                    'floor' => trim((string) ($row['floor'] ?? '')) ?: null,
                ]
            );
        }

        $guest = ReservationCreator::findOrCreateGuest($this->hotel->id, [
            'phone_number' => $phone,
            'first_name' => trim((string) ($row['guest_first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($row['guest_last_name'] ?? '')) ?: null,
            'email' => trim((string) ($row['guest_email'] ?? '')) ?: null,
        ]);

        $reservationId = trim((string) ($row['reservation_id'] ?? '')) ?: 'RES-'.strtoupper(Str::random(8));

        $reservation = ReservationCreator::create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room?->id,
            'reservation_id' => $reservationId,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'status' => trim((string) ($row['status'] ?? '')) ?: ReservationStatus::PENDING->value,
            'adults' => (int) ($row['adults'] ?? 1) ?: 1,
            'children' => (int) ($row['children'] ?? 0),
            'source' => trim((string) ($row['source'] ?? '')) ?: 'import',
            'special_requests' => trim((string) ($row['special_requests'] ?? '')) ?: null,
            'reservation_value' => (float) ($row['reservation_value'] ?? 0),
            'currency' => trim((string) ($row['currency'] ?? '')) ?: $this->hotel->currency,
        ]);

        ReservationCreator::syncRoomOccupancy($reservation);

        $this->imported++;
    }
}
