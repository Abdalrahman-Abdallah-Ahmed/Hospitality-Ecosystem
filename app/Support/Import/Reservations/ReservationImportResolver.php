<?php

namespace App\Support\Import\Reservations;

use App\Enums\BookingStatus;
use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Import\PhoneNormalizer;
use App\Support\PhoneNumber;
use App\Support\Reservations\RoomAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolve stage: checks the mapped drafts against what the hotel already has
 * (research R7). It only reads, so a dry run is this stage's result as it
 * stands; every query names the hotel explicitly.
 *
 * - a reference the hotel already holds is a duplicate (FR-021), noting when
 *   the file's data differs; a soft-deleted one is restored by the writer;
 * - each person in the file is matched to the hotel's guests by phone, email
 *   and old-system id separately; more than one guest found makes their
 *   reservations unresolved (FR-018, FR-019);
 * - a reference-less draft repeating a reservation the guest already has is
 *   a duplicate (FR-022);
 * - room numbers and room types are looked up, and what is missing is listed
 *   as created (FR-016);
 * - a room held on overlapping nights by an existing reservation is left off
 *   the line (FR-012a);
 * - activity bookings are matched by name and given a status from the
 *   reservation (FR-015).
 */
class ReservationImportResolver
{
    private ImportPlan $plan;

    private Hotel $hotel;

    public function resolve(ImportPlan $plan, Hotel $hotel, CarbonImmutable $today): ImportPlan
    {
        $this->plan = $plan;
        $this->hotel = $hotel;

        $this->referenceDuplicates();
        $this->guests();
        $this->contentDuplicates();
        $this->rooms();
        $this->roomClashes();
        $this->bookings($today);

        return $plan;
    }

    private function referenceDuplicates(): void
    {
        $references = collect($this->plan->drafts)->pluck('reference')->filter()->values();
        if ($references->isEmpty()) {
            return;
        }

        $existing = Reservation::withoutGlobalScope('hotel')->withTrashed()
            ->with(['guest' => fn ($query) => $query->withoutGlobalScope('hotel')->withTrashed()])
            ->withCount(['reservationRooms as live_lines' => fn ($query) => $query->withoutGlobalScope('hotel')->where('status', '!=', ReservationRoomStatus::CANCELLED->value)])
            ->where('hotel_id', $this->hotel->id)
            ->whereIn(DB::raw('lower(reservation_id)'), $references->map(fn (string $reference) => mb_strtolower($reference))->all())
            ->get()
            ->keyBy(fn (Reservation $reservation) => mb_strtolower($reservation->reservation_id));

        foreach ($this->plan->drafts as $position => $draft) {
            $reservation = $draft['reference'] !== null ? $existing->get(mb_strtolower($draft['reference'])) : null;
            if (! $reservation) {
                continue;
            }

            if ($reservation->trashed()) {
                // ReservationCreator::create() restores a trashed code.
                $this->plan->drafts[$position]['reference'] = $reservation->reservation_id;
                $this->plan->drafts[$position]['restore'] = true;

                continue;
            }

            $this->plan->settle($draft['rows'], ImportRowOutcome::DUPLICATE, ImportStage::MAP, "Reservation {$reservation->reservation_id} already exists at this hotel.", [], $reservation->id);

            if ($differs = $this->differences($draft, $reservation)) {
                $this->plan->warn($draft['position'], ImportWarning::DATA_DIFFERS, 'The file differs from the stored reservation in '.implode(', ', $differs).'; the stored reservation was left unchanged. Edit it by hand if the file is right.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    private function differences(array $draft, Reservation $reservation): array
    {
        $attributes = $draft['attributes'];
        $differs = [];

        if ($reservation->arrival_date?->toDateString() !== $attributes['arrival_date']) {
            $differs[] = 'arrival_date';
        }
        if ($reservation->departure_date?->toDateString() !== $attributes['departure_date']) {
            $differs[] = 'departure_date';
        }
        if ($reservation->status?->value !== $attributes['status']) {
            $differs[] = 'status';
        }
        if ((int) $reservation->live_lines !== count($draft['units'])) {
            $differs[] = 'rooms';
        }

        $primary = collect($draft['guests'])->firstWhere('is_primary', true);
        $stored = $reservation->guest;
        if ($primary && $stored && array_intersect($this->lookupKeys($primary), $this->storedKeys($stored)) === []) {
            $differs[] = 'primary guest';
        }

        return $differs;
    }

    /** @return list<string> */
    private function storedKeys(Guest $guest): array
    {
        return ReservationMapper::identityKeys([
            'guest_id' => $guest->id,
            'phone' => PhoneNumber::digits($guest->phone_number),
            'email' => $guest->email ? mb_strtolower(trim($guest->email)) : null,
            'external_id' => $guest->external_source === 'import' ? $guest->external_id : null,
        ]);
    }

    /**
     * FR-018/FR-019: a person is reused only when everything the file says
     * about them (phone, email, old-system id, guest id, on any row) points
     * at one existing guest of this hotel. Names never match anyone.
     */
    private function guests(): void
    {
        $people = [];
        foreach ($this->plan->drafts as $draft) {
            foreach ($draft['guests'] as $guest) {
                $people[$guest['person']] ??= ['row' => $guest['row'], 'keys' => [], 'guest_ids' => []];
                $people[$guest['person']]['keys'] = array_values(array_unique([...$people[$guest['person']]['keys'], ...$this->lookupKeys($guest)]));
                if ($guest['guest_id'] !== null) {
                    $people[$guest['person']]['guest_ids'][] = $guest['guest_id'];
                }
            }
        }

        if ($people === []) {
            return;
        }

        $byKey = $this->existingGuests(array_merge(...array_column($people, 'keys')));
        $verdicts = array_map(fn (array $person) => $this->verdict($person, $byKey), $people);

        foreach ($this->plan->drafts as $position => $draft) {
            foreach ($draft['guests'] as $i => $guest) {
                $verdict = $verdicts[$guest['person']];
                if (isset($verdict['problem'])) {
                    $this->plan->settle($draft['rows'], ...$verdict['problem']);

                    continue 2;
                }

                $this->plan->drafts[$position]['guests'][$i]['existing_id'] = $verdict['existing_id'];
            }
        }
    }

    /**
     * The existing guest a file person is, or why they can't be matched.
     *
     * @param  array{row: int, keys: list<string>, guest_ids: list<string>}  $person
     * @param  array<string, list<Guest>>  $byKey
     * @return array{existing_id?: ?string, problem?: array{0: ImportRowOutcome, 1: ImportStage, 2: string, 3: list<string>}}
     */
    private function verdict(array $person, array $byKey): array
    {
        $matches = collect($person['keys'])->flatMap(fn (string $key) => $byKey[$key] ?? [])->unique('id')->values();

        foreach ($person['guest_ids'] as $guestId) {
            if (! $matches->contains('id', $guestId)) {
                return ['problem' => [ImportRowOutcome::INVALID, ImportStage::MAP, "The guest id {$guestId} on row {$person['row']} is not a guest of this hotel.", ['guests']]];
            }
        }

        if ($matches->count() > 1) {
            $names = $matches->map(fn (Guest $match) => trim("{$match->first_name} {$match->last_name}") ?: 'an unnamed guest')->implode(' and ');

            return ['problem' => [ImportRowOutcome::UNRESOLVED, ImportStage::MAP, "The guest on row {$person['row']} matches more than one existing guest ({$names}): their phone, email or guest id belong to different profiles. Correct the file or merge the guests, then import again.", ['guest_phone', 'guest_email', 'guest_external_id']]];
        }

        return ['existing_id' => $matches->first()?->id];
    }

    /**
     * A file guest's identity keys, plus their phone as staff may have typed
     * it at this hotel without the country code (stored before the import
     * normalized phones).
     *
     * @param  array<string, mixed>  $guest
     * @return list<string>
     */
    private function lookupKeys(array $guest): array
    {
        $national = $guest['phone'] ? PhoneNormalizer::nationalDigits($guest['phone'], $this->hotel->country_code) : null;

        return [...ReservationMapper::identityKeys($guest), ...($national ? ["phone:{$national}"] : [])];
    }

    /**
     * Every existing guest of the hotel sharing an identity key with anyone
     * in the file, keyed by that key. Trashed guests count: the writer
     * restores them rather than creating a second profile.
     *
     * @param  list<string>  $keys
     * @return array<string, list<Guest>>
     */
    private function existingGuests(array $keys): array
    {
        $values = fn (string $prefix) => collect($keys)
            ->filter(fn (string $key) => str_starts_with($key, $prefix))
            ->map(fn (string $key) => substr($key, strlen($prefix)))
            ->unique()
            ->values();
        [$phones, $emails, $externals, $ids] = [$values('phone:'), $values('email:'), $values('ext:'), $values('id:')];

        $guests = Guest::withoutGlobalScope('hotel')->withTrashed()
            ->where('hotel_id', $this->hotel->id)
            ->where(function ($query) use ($phones, $emails, $externals, $ids) {
                $query->whereRaw('false');
                foreach ($phones->chunk(500) as $chunk) {
                    $query->orWhereRaw(PhoneNumber::DIGITS_SQL.' in ('.implode(',', array_fill(0, $chunk->count(), '?')).')', $chunk->values()->all());
                }
                if ($emails->isNotEmpty()) {
                    $query->orWhereIn('identity_hash', $emails->map(fn (string $email) => 'email:'.hash('sha256', $email))->all());
                }
                if ($externals->isNotEmpty()) {
                    $query->orWhere(fn ($query) => $query->where('external_source', 'import')->whereIn(DB::raw('lower(external_id)'), $externals->all()));
                }
                if ($ids->isNotEmpty()) {
                    $query->orWhereIn('id', $ids->filter(fn (string $id) => Str::isUuid($id))->all());
                }
            })
            ->get();

        $byKey = [];
        foreach ($guests as $guest) {
            foreach ($this->storedKeys($guest) as $key) {
                $byKey[$key][] = $guest;
            }
        }

        return $byKey;
    }

    /**
     * FR-022 against the hotel: a reference-less draft whose primary guest
     * already exists, with a live reservation on the same dates and room
     * types, is a duplicate of it.
     */
    private function contentDuplicates(): void
    {
        foreach ($this->plan->drafts as $draft) {
            if ($draft['reference'] !== null) {
                continue;
            }

            $primary = collect($draft['guests'])->firstWhere('is_primary', true);
            if (! $primary || ! $primary['existing_id']) {
                continue;
            }

            $wanted = $this->typeNames($draft['units']);
            $match = Reservation::withoutGlobalScope('hotel')
                ->with(['reservationRooms' => fn ($query) => $query->withoutGlobalScope('hotel')->with(['roomType' => fn ($query) => $query->withoutGlobalScope('hotel')->withTrashed()])->where('status', '!=', ReservationRoomStatus::CANCELLED->value)])
                ->where('hotel_id', $this->hotel->id)
                ->where('guest_id', $primary['existing_id'])
                ->whereDate('arrival_date', $draft['attributes']['arrival_date'])
                ->whereDate('departure_date', $draft['attributes']['departure_date'])
                ->get()
                ->first(fn (Reservation $reservation) => $this->sameTypes($reservation->reservationRooms, $wanted));

            if ($match) {
                $this->plan->settle($draft['rows'], ImportRowOutcome::DUPLICATE, ImportStage::MAP, "This matches reservation {$match->reservation_id} (same guest, dates and room types).", [], $match->id);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @return list<string>
     */
    private function typeNames(array $units): array
    {
        $default = $this->defaultTypeName();
        $names = array_map(function (array $unit) use ($default): string {
            $room = $unit['room_number'] !== null ? $this->existingRooms()->get(mb_strtolower($unit['room_number'])) : null;

            return mb_strtolower($room?->roomType?->name ?? $unit['room_type'] ?? $default);
        }, $units);
        sort($names);

        return $names;
    }

    /**
     * @param  Collection<int, ReservationRoom>  $lines
     * @param  list<string>  $wanted
     */
    private function sameTypes(Collection $lines, array $wanted): bool
    {
        $names = $lines->map(fn (ReservationRoom $line) => mb_strtolower((string) $line->roomType?->name))->sort()->values()->all();

        return $names === $wanted;
    }

    /**
     * Fills each unit's room and room type from what the hotel has, and lists
     * what the writer will have to create.
     */
    private function rooms(): void
    {
        $types = $this->existingTypes();

        foreach ($this->plan->drafts as $position => $draft) {
            foreach ($draft['units'] as $i => $unit) {
                $room = $unit['room_number'] !== null ? $this->existingRooms()->get(mb_strtolower($unit['room_number'])) : null;

                if ($room) {
                    $unit['room_id'] = $room->id;
                    $unit['room_type_id'] = $room->room_type_id;
                } else {
                    if ($unit['room_number'] !== null) {
                        $this->plan->noteCreatedRoom($unit['room_number']);
                    }

                    $name = $unit['room_type'] ?? $this->defaultTypeName();
                    $unit['room_id'] = null;
                    $unit['room_type_id'] = $unit['room_type'] !== null
                        ? $types->get(mb_strtolower($unit['room_type']))?->id
                        : $this->defaultType()?->id;

                    if ($unit['room_type_id'] === null) {
                        $this->plan->noteCreatedRoomType($name);
                    }
                }

                $this->plan->drafts[$position]['units'][$i] = $unit;
            }
        }
    }

    /**
     * FR-012a against the hotel: an assigned room already held on any of the
     * draft's nights by a line that holds it (RoomAssignmentRules::holdingLines())
     * is left off the draft's line.
     */
    private function roomClashes(): void
    {
        $drafts = collect($this->plan->drafts)->filter(fn (array $draft) => in_array(ReservationStatus::from($draft['attributes']['status']), ReservationStatus::holdingInventory(), true));
        $roomIds = $drafts->flatMap(fn (array $draft) => array_column(array_filter($draft['units'], fn (array $unit) => $unit['assign'] && $unit['room_id']), 'room_id'))->unique()->values();

        if ($roomIds->isEmpty()) {
            return;
        }

        $lines = RoomAssignmentRules::holdingLines()
            ->where('reservation_rooms.hotel_id', $this->hotel->id)
            ->whereIn('reservation_rooms.room_id', $roomIds->all())
            ->where('reservations.arrival_date', '<', $drafts->max(fn (array $draft) => $draft['attributes']['departure_date']))
            ->where('reservations.departure_date', '>', $drafts->min(fn (array $draft) => $draft['attributes']['arrival_date']))
            ->get(['reservation_rooms.room_id', 'reservations.arrival_date', 'reservations.departure_date', 'reservations.reservation_id as code'])
            ->groupBy('room_id');

        foreach ($drafts as $position => $draft) {
            foreach ($draft['units'] as $i => $unit) {
                if (! $unit['assign'] || ! $unit['room_id']) {
                    continue;
                }

                $clash = collect($lines->get($unit['room_id'], []))->first(fn ($line) => CarbonImmutable::parse($line->arrival_date)->toDateString() < $draft['attributes']['departure_date']
                    && CarbonImmutable::parse($line->departure_date)->toDateString() > $draft['attributes']['arrival_date']);

                if ($clash) {
                    $this->plan->drafts[$position]['units'][$i]['assign'] = false;
                    $this->plan->warn($unit['row'], ImportWarning::ROOM_CLASH, "Room {$unit['room_number']} is booked for {$clash->code} on overlapping nights, so this line was imported without a room.");

                    if ($draft['attributes']['status'] === ReservationStatus::CHECKED_IN->value) {
                        $this->plan->warn($unit['row'], ImportWarning::NO_ROOM_ASSIGNED, 'The guest is in house but no room is assigned to this line; assign one at the front desk.');
                    }
                }
            }
        }
    }

    /**
     * FR-015: a booking is attached when its activity name matches exactly
     * one of the hotel's activities (active or not); its status follows the
     * reservation (clarified 2026-10-23).
     */
    private function bookings(CarbonImmutable $today): void
    {
        $activities = Activity::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->get(['id', 'name'])
            ->groupBy(fn (Activity $activity) => mb_strtolower(trim($activity->name)));

        foreach ($this->plan->drafts as $position => $draft) {
            $status = ReservationStatus::from($draft['attributes']['status']);
            $kept = [];

            foreach ($draft['bookings'] as $booking) {
                $matches = $activities->get(mb_strtolower(trim($booking['name'])), collect());

                if ($matches->count() !== 1) {
                    $why = $matches->isEmpty() ? 'no activity at this hotel has that name' : 'more than one activity has that name';
                    $this->plan->warn($booking['row'], ImportWarning::BOOKING_UNRESOLVED, "The booking for \"{$booking['name']}\" was not imported: {$why}.");

                    continue;
                }

                $booking['activity_id'] = $matches->first()->id;
                $booking['pax'] ??= max(1, (int) $draft['attributes']['adults'] + (int) $draft['attributes']['children']);
                $booking['status'] = match (true) {
                    in_array($status, [ReservationStatus::CANCELLED, ReservationStatus::NO_SHOW], true) => BookingStatus::CANCELLED->value,
                    $booking['date'] < $today->toDateString() && in_array($status, [ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT], true) => BookingStatus::REALISED->value,
                    default => BookingStatus::CONFIRMED->value,
                };
                $kept[] = $booking;
            }

            $this->plan->drafts[$position]['bookings'] = $kept;
        }
    }

    /** @var Collection<string, Room>|null */
    private ?Collection $rooms = null;

    /** @return Collection<string, Room> */
    private function existingRooms(): Collection
    {
        if ($this->rooms === null) {
            $numbers = collect($this->plan->drafts)
                ->flatMap(fn (array $draft) => array_column($draft['units'], 'room_number'))
                ->filter()
                ->map(fn (string $number) => mb_strtolower($number))
                ->unique();

            $this->rooms = $numbers->isEmpty() ? collect() : Room::withoutGlobalScope('hotel')
                ->with(['roomType' => fn ($query) => $query->withoutGlobalScope('hotel')->withTrashed()])
                ->where('hotel_id', $this->hotel->id)
                ->whereIn(DB::raw('lower(room_number)'), $numbers->values()->all())
                ->get()
                ->keyBy(fn (Room $room) => mb_strtolower($room->room_number));
        }

        return $this->rooms;
    }

    /** @return Collection<string, RoomType> */
    private function existingTypes(): Collection
    {
        return RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->get()
            ->keyBy(fn (RoomType $type) => mb_strtolower($type->name));
    }

    private RoomType|false|null $defaultType = false;

    /** The type RoomType::resolveFor() would pick for a row with no type. */
    private function defaultType(): ?RoomType
    {
        if ($this->defaultType === false) {
            $this->defaultType = RoomType::withoutGlobalScope('hotel')
                ->where('hotel_id', $this->hotel->id)
                ->where('is_active', true)
                ->oldest()
                ->first();
        }

        return $this->defaultType;
    }

    private function defaultTypeName(): string
    {
        return $this->defaultType()?->name ?? RoomType::DEFAULT_NAME;
    }
}
