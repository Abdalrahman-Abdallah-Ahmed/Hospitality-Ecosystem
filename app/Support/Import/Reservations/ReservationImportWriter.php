<?php

namespace App\Support\Import\Reservations;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\ImportRowOutcome;
use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\PhoneNumber;
use App\Support\Reservations\ReservationCreator;
use App\Support\Reservations\RoomAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import stage: writes one draft as one reservation, all or nothing
 * (FR-024), through the same write path as every other entry point
 * (ReservationCreator with `fromImport`, BookingService), so SPEC-011/012
 * rules, stays and room occupancy behave as they do anywhere else.
 *
 * Room assignments are checked again under the room-type locks just before
 * writing: staff may have booked a room since the resolver looked. A clash
 * found now leaves the line without a room, the same outcome FR-012a gives.
 */
class ReservationImportWriter
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array{outcome: ImportRowOutcome, reservation: Reservation, reason: ?string, warnings: list<array{row: int, code: string, message: string}>}
     */
    public function write(array $draft, Hotel $hotel): array
    {
        return DB::transaction(function () use ($draft, $hotel) {
            $warnings = [];
            $reference = $draft['reference'];

            // Created since the resolver looked (another upload, or staff).
            // References match ignoring case, as the mapper groups them.
            $existing = $reference === null ? null : Reservation::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel->id)
                ->whereRaw('lower(reservation_id) = ?', [mb_strtolower($reference)])
                ->first();

            if ($existing) {
                return ['outcome' => ImportRowOutcome::DUPLICATE, 'reservation' => $existing, 'reason' => "Reservation {$existing->reservation_id} already exists at this hotel.", 'warnings' => []];
            }

            $units = $this->rooms($draft['units'], $hotel);
            $guests = $this->guests($draft['guests'], $hotel);
            $primary = collect($guests)->firstWhere('is_primary', true);

            $this->availability->lockTypes($hotel->id, array_column($units, 'room_type_id'));

            $live = in_array(ReservationStatus::from($draft['attributes']['status']), ReservationStatus::holdingInventory(), true);
            foreach ($units as $i => $unit) {
                if ($live && $unit['assign'] && ($code = $this->heldBy($unit['room_id'], $draft['attributes'], $hotel))) {
                    $units[$i]['assign'] = false;
                    $warnings[] = ['row' => $unit['row'], 'code' => ImportWarning::ROOM_CLASH, 'message' => "Room {$unit['room_number']} is booked for {$code} on overlapping nights, so this line was imported without a room."];
                }
            }

            $attributes = $draft['attributes'];
            // A stay recorded as departed is history: no memory extraction.
            $reservation = Stay::withoutMemoryExtraction(fn () => ReservationCreator::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $primary['guest_id'],
                'reservation_id' => $reference ?? $this->generatedReference($hotel),
                'arrival_date' => $attributes['arrival_date'],
                'departure_date' => $attributes['departure_date'],
                'status' => $attributes['status'],
                'adults' => $attributes['adults'],
                'children' => $attributes['children'],
                'children_ages' => $attributes['children_ages'],
                'source' => $attributes['source'] ?? 'import',
                'special_requests' => $attributes['special_requests'],
                'reservation_value' => $attributes['reservation_value'] ?? 0,
                'currency' => $attributes['currency'] ?? $hotel->currency,
                'guests' => array_map(fn (array $guest) => ['guest_id' => $guest['guest_id'], 'is_primary' => $guest['is_primary']], $guests),
            ], array_map(fn (array $unit) => [
                'room_type_id' => $unit['room_type_id'],
                'room_id' => $unit['assign'] ? $unit['room_id'] : null,
            ], $units), fromImport: true));

            if (! empty($draft['restore'])) {
                $warnings[] = ['row' => $draft['position'], 'code' => ImportWarning::RESTORED, 'message' => "Reservation {$reference} had been deleted; it was restored with the file's data."];
            }

            foreach ($draft['bookings'] as $booking) {
                $this->book($booking, $reservation, $primary['guest_id'], $hotel);
            }

            return ['outcome' => ImportRowOutcome::IMPORTED, 'reservation' => $reservation, 'reason' => null, 'warnings' => $warnings];
        });
    }

    /**
     * Each unit with its room type and room, creating the ones the hotel does
     * not have yet (FR-016), as the import always has.
     *
     * @param  list<array<string, mixed>>  $units
     * @return list<array<string, mixed>>
     */
    private function rooms(array $units, Hotel $hotel): array
    {
        foreach ($units as $i => $unit) {
            if ($unit['room_number'] !== null) {
                $room = Room::withoutGlobalScope('hotel')
                    ->where('hotel_id', $hotel->id)
                    ->whereRaw('lower(room_number) = ?', [mb_strtolower($unit['room_number'])])
                    ->first()
                    ?? Room::create([
                        'hotel_id' => $hotel->id,
                        'room_number' => $unit['room_number'],
                        'room_type_id' => RoomType::resolveFor($hotel->id, $unit['room_type'])->id,
                        'floor' => $unit['floor'],
                    ]);

                $units[$i]['room_id'] = $room->id;
                $units[$i]['room_type_id'] = $room->room_type_id;

                continue;
            }

            $units[$i]['room_id'] = null;
            $units[$i]['room_type_id'] = RoomType::resolveFor($hotel->id, $unit['room_type'])->id;
        }

        return $units;
    }

    /**
     * The draft's guests as guest records: the existing guest the resolver
     * matched (restored if deleted), one this import already created for the
     * same person, or a new one. An existing guest's details are never
     * overwritten; empty ones are filled in (FR-020).
     *
     * @param  list<array<string, mixed>>  $guests
     * @return list<array{guest_id: string, is_primary: bool}>
     */
    private function guests(array $guests, Hotel $hotel): array
    {
        return array_map(function (array $guest) use ($hotel): array {
            $record = $guest['existing_id'] !== null
                ? Guest::withoutGlobalScope('hotel')->withTrashed()->where('hotel_id', $hotel->id)->findOrFail($guest['existing_id'])
                : $this->createdEarlier($guest, $hotel);

            if (! $record) {
                $record = Guest::create([
                    'hotel_id' => $hotel->id,
                    'first_name' => $guest['first_name'],
                    'last_name' => $guest['last_name'],
                    'phone_number' => $guest['phone'],
                    'email' => $guest['email'],
                    'external_id' => $guest['external_id'],
                    'external_source' => $guest['external_id'] !== null ? 'import' : null,
                ]);
            } else {
                $this->fillEmpty($record, $guest);
            }

            if ($record->trashed()) {
                $record->restore();
            }

            return ['guest_id' => $record->id, 'is_primary' => $guest['is_primary']];
        }, $guests);
    }

    /**
     * A guest an earlier reservation in this file created for the same
     * person. The mapper already proved the person's details point at one
     * profile, so more than one match means the data changed under the run.
     *
     * @param  array<string, mixed>  $guest
     */
    private function createdEarlier(array $guest, Hotel $hotel): ?Guest
    {
        $matches = Guest::withoutGlobalScope('hotel')->withTrashed()
            ->where('hotel_id', $hotel->id)
            ->where(function ($query) use ($guest) {
                $query->whereRaw('false');
                if ($guest['phone']) {
                    $query->orWhereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$guest['phone']]);
                }
                if ($guest['email']) {
                    $query->orWhere('identity_hash', 'email:'.hash('sha256', $guest['email']));
                }
                if ($guest['external_id']) {
                    $query->orWhere(fn ($query) => $query->where('external_source', 'import')->whereRaw('lower(external_id) = ?', [mb_strtolower($guest['external_id'])]));
                }
            })
            ->get();

        if ($matches->count() > 1) {
            throw new DraftRejected('a guest on it now matches more than one guest profile at this hotel. Import the file again to have it reported.');
        }

        return $matches->first();
    }

    /** @param array<string, mixed> $guest */
    private function fillEmpty(Guest $record, array $guest): void
    {
        $fill = array_filter([
            'first_name' => blank($record->first_name) ? $guest['first_name'] : null,
            'last_name' => blank($record->last_name) ? $guest['last_name'] : null,
            'phone_number' => blank($record->phone_number) ? $guest['phone'] : null,
            'email' => blank($record->email) ? $guest['email'] : null,
        ], fn ($value) => $value !== null);

        if (blank($record->external_id) && $guest['external_id'] !== null) {
            $fill += ['external_id' => $guest['external_id'], 'external_source' => 'import'];
        }

        if ($fill !== []) {
            $record->update($fill);
        }
    }

    /**
     * The code of a live reservation holding `$roomId` on any of the given
     * nights (RoomAssignmentRules::holdingLines()).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function heldBy(?string $roomId, array $attributes, Hotel $hotel): ?string
    {
        if ($roomId === null) {
            return null;
        }

        return RoomAssignmentRules::holdingLines()
            ->where('reservation_rooms.hotel_id', $hotel->id)
            ->where('reservation_rooms.room_id', $roomId)
            ->where('reservations.arrival_date', '<', $attributes['departure_date'])
            ->where('reservations.departure_date', '>', $attributes['arrival_date'])
            ->value('reservations.reservation_id');
    }

    /**
     * A booking from the file (FR-015), recorded as it was: status from the
     * resolver, origin `import`, no availability check. Charged to the folio
     * when the activity has a price, otherwise included; the old system
     * gives no charge model.
     *
     * @param  array<string, mixed>  $booking
     */
    private function book(array $booking, Reservation $reservation, string $guestId, Hotel $hotel): void
    {
        $activity = Activity::withoutGlobalScope('hotel')->withTrashed()->where('hotel_id', $hotel->id)->findOrFail($booking['activity_id']);
        $status = BookingStatus::from($booking['status']);
        $timezone = $hotel->timezone ?: 'UTC';
        $at = CarbonImmutable::parse($booking['date'].' '.($booking['time'] ?? '00:00'), $timezone);

        $this->bookings->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guestId,
            'reservation_id' => $reservation->id,
            'activity_id' => $activity->id,
            'item_name' => $activity->name,
            'scheduled_for' => $booking['time'] !== null ? $booking['date'].' '.$booking['time'] : null,
            'scheduled_date' => $booking['time'] === null ? $booking['date'] : null,
            'pax' => $booking['pax'],
            'charge_model' => ((float) $activity->price) > 0 ? ChargeModel::FOLIO->value : ChargeModel::INCLUDED->value,
            'expected_value' => $activity->price,
            'currency' => $activity->currency ?? $hotel->currency,
            'origin' => BookingOrigin::IMPORT->value,
            'channel' => 'import',
            'status' => $status->value,
            'confirmed_at' => $status !== BookingStatus::CANCELLED ? $at->utc() : null,
            'realised_at' => $status === BookingStatus::REALISED ? $at->utc() : null,
            'cancelled_at' => $status === BookingStatus::CANCELLED ? $at->utc() : null,
            'cancellation_reason' => $status === BookingStatus::CANCELLED ? 'Imported with a cancelled or no-show reservation.' : null,
            'from_import' => true,
        ]);
    }

    /** FR-022: a reservation without a reference gets one, as the import always gave. */
    private function generatedReference(Hotel $hotel): string
    {
        do {
            $reference = 'RES-'.strtoupper(Str::random(8));
        } while (ReservationCreator::isReservationIdInUse($hotel->id, $reference));

        return $reference;
    }
}
