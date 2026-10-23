<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;
use App\Enums\ReservationStatus;
use App\Support\Reservations\ReservationRoomSync;
use Carbon\CarbonImmutable;

/**
 * Map stage: turns normalized rows into reservation drafts over the whole
 * file (research R6). Everything that depends on more than one row is
 * settled here, in file order:
 *
 * - rows sharing a reference become one reservation, and must agree on its
 *   fields (FR-011);
 * - room units are counted per FR-012 (clarified 2026-10-23);
 * - guests are linked across rows, one primary (FR-013), and the party must
 *   hold them (FR-013a);
 * - statuses are checked against the dates (FR-017b);
 * - the same person named twice in the file is one guest, and details that
 *   point at two people make the reservation unresolved (FR-019);
 * - a reference-less copy of an earlier reservation is a duplicate (FR-022);
 * - a room booked twice on the same nights keeps only the first (FR-012a).
 *
 * It reads nothing from the database: what the hotel already has is the
 * resolver's job.
 */
class ReservationMapper
{
    /** Reservation fields every row of a reservation must agree on. */
    private const SHARED_FIELDS = [
        'arrival_date', 'departure_date', 'status', 'source', 'adults', 'children',
        'children_ages', 'special_requests', 'reservation_value', 'currency',
    ];

    private ImportPlan $plan;

    /**
     * @param  list<NormalizedRow>  $rows
     */
    public function map(array $rows, CarbonImmutable $today): ImportPlan
    {
        $this->plan = new ImportPlan;

        $groups = [];
        foreach ($rows as $row) {
            $this->plan->addRow($row->rowNumber, $row->reference);
            foreach ($row->warnings as $warning) {
                $this->plan->warn($row->rowNumber, $warning['code'], $warning['message']);
            }

            if ($row->blank) {
                $this->plan->settle([$row->rowNumber], ImportRowOutcome::SKIPPED, ImportStage::PARSE, 'The row is empty.');

                continue;
            }

            $key = $row->reference !== null ? 'ref:'.mb_strtolower($row->reference) : 'row:'.$row->rowNumber;
            $groups[$key][] = $row;
        }

        $drafts = [];
        foreach ($groups as $group) {
            if ($draft = $this->draft($group, $today)) {
                $drafts[] = $draft;
            }
        }

        $drafts = $this->identifyPeople($drafts);
        $drafts = array_values(array_filter(array_map(fn (array $draft) => $this->guestsAndParty($draft), $drafts)));
        $drafts = $this->withoutContentDuplicates($drafts);
        $drafts = $this->withoutRoomClashes($drafts, $today);

        foreach ($drafts as $draft) {
            $this->plan->stage($draft['rows'], ImportStage::MAP);
            $this->plan->drafts[$draft['position']] = $draft;
        }

        return $this->plan;
    }

    /**
     * One reservation's rows as a draft, or null when they make it invalid.
     *
     * @param  non-empty-list<NormalizedRow>  $group
     * @return array<string, mixed>|null
     */
    private function draft(array $group, CarbonImmutable $today): ?array
    {
        $rowNumbers = array_map(fn (NormalizedRow $row) => $row->rowNumber, $group);

        $errors = [];
        foreach ($group as $row) {
            $errors += $row->errors;
        }

        if ($errors !== []) {
            $first = array_key_first($errors);
            $this->invalid($rowNumbers, ImportStage::VALIDATE, $this->withRow($group, $first, $errors[$first]), array_keys($errors));

            return null;
        }

        $attributes = [];
        $conflicts = [];
        foreach (self::SHARED_FIELDS as $field) {
            $values = collect($group)->map(fn (NormalizedRow $row) => $this->shared($row, $field))
                ->reject(fn (mixed $value) => $value === null);

            if ($values->unique(fn (mixed $value) => json_encode($value))->count() > 1) {
                $conflicts[] = $field;
            }

            $attributes[$field] = $values->first();
        }

        if ($conflicts !== []) {
            $this->invalid($rowNumbers, ImportStage::MAP, 'The rows of this reservation disagree on '.implode(', ', $conflicts).'. Make every row of the reservation match.', $conflicts);

            return null;
        }

        foreach (['arrival_date', 'departure_date'] as $field) {
            if ($attributes[$field] === null) {
                $this->invalid($rowNumbers, ImportStage::VALIDATE, "{$field} is empty.", [$field]);

                return null;
            }
        }

        if ($attributes['departure_date'] <= $attributes['arrival_date']) {
            $this->invalid($rowNumbers, ImportStage::VALIDATE, "The departure ({$attributes['departure_date']}) must be after the arrival ({$attributes['arrival_date']}).", ['departure_date']);

            return null;
        }

        $units = $this->units($group, $rowNumbers);
        if ($units === null) {
            return null;
        }

        $attributes['status'] ??= ReservationStatus::PENDING->value;
        $draft = [
            'position' => $rowNumbers[0],
            'rows' => $rowNumbers,
            'reference' => $group[0]->reference,
            'status_label' => collect($group)->first(fn (NormalizedRow $row) => $row->statusLabel !== null)?->statusLabel,
            'attributes' => $attributes,
            'units' => $units,
            'mentions' => $this->mentions($group),
            'guests' => [],
            'bookings' => $this->bookings($group),
        ];

        return $this->statusAgainstDates($draft, $today);
    }

    private function shared(NormalizedRow $row, string $field): mixed
    {
        return match ($field) {
            'arrival_date' => $row->arrival?->toDateString(),
            'departure_date' => $row->departure?->toDateString(),
            'status' => $row->statusGiven ? $row->status?->value : null,
            'source' => $row->source,
            'adults' => $row->adults,
            'children' => $row->children,
            'children_ages' => $row->childrenAges,
            'special_requests' => $row->specialRequests,
            'reservation_value' => $row->reservationValue,
            'currency' => $row->currency,
        };
    }

    /**
     * Room units per FR-012 (clarified 2026-10-23):
     *
     * - each distinct room number is one unit, however many rows repeat it;
     * - unnumbered rows add one unit of their room type the first time the
     *   type appears, later ones only add guests; a `room_count` for the type
     *   sets the number instead;
     * - rows with no room columns only add guests;
     * - a reservation with no units gets one of the hotel's default type.
     *
     * @param  non-empty-list<NormalizedRow>  $group
     * @param  list<int>  $rowNumbers
     * @return list<array{room_number: ?string, room_type: ?string, floor: ?string, row: int}>|null
     */
    private function units(array $group, array $rowNumbers): ?array
    {
        $numbered = [];
        $typed = [];

        foreach ($group as $row) {
            if ($row->roomNumber !== null) {
                $key = mb_strtolower($row->roomNumber);
                $numbered[$key] ??= ['room_number' => $row->roomNumber, 'room_type' => $row->roomType, 'floor' => $row->floor, 'row' => $row->rowNumber];
                $numbered[$key]['room_type'] ??= $row->roomType;
                $numbered[$key]['floor'] ??= $row->floor;

                continue;
            }

            if ($row->roomType === null) {
                continue;
            }

            $key = mb_strtolower($row->roomType);
            $typed[$key] ??= ['room_type' => $row->roomType, 'row' => $row->rowNumber, 'counts' => []];
            if ($row->roomCount !== null) {
                $typed[$key]['counts'][] = $row->roomCount;
            }
        }

        $units = array_values($numbered);
        foreach ($typed as $type) {
            $counts = array_values(array_unique($type['counts']));
            if (count($counts) > 1) {
                $this->invalid($rowNumbers, ImportStage::MAP, "The rows of this reservation give different room_count values for {$type['room_type']}. Use one value.", ['room_count']);

                return null;
            }

            for ($i = 0; $i < ($counts[0] ?? 1); $i++) {
                $units[] = ['room_number' => null, 'room_type' => $type['room_type'], 'floor' => null, 'row' => $type['row']];
            }
        }

        if ($units === []) {
            $units[] = ['room_number' => null, 'room_type' => null, 'floor' => null, 'row' => $rowNumbers[0]];
        }

        if (count($units) > ReservationRoomSync::MAX_UNITS) {
            $this->invalid($rowNumbers, ImportStage::MAP, 'This reservation has '.count($units).' rooms; a reservation can hold at most '.ReservationRoomSync::MAX_UNITS.'.', ['room_number', 'room_count']);

            return null;
        }

        return $units;
    }

    /**
     * @param  non-empty-list<NormalizedRow>  $group
     * @return list<array<string, mixed>>
     */
    private function mentions(array $group): array
    {
        $mentions = [];
        foreach ($group as $row) {
            foreach ($row->guests as $guest) {
                $mentions[] = [...$guest, 'row' => $row->rowNumber];
            }
        }

        return $mentions;
    }

    /**
     * @param  non-empty-list<NormalizedRow>  $group
     * @return list<array<string, mixed>>
     */
    private function bookings(array $group): array
    {
        $bookings = [];
        foreach ($group as $row) {
            if ($row->activity !== null) {
                $bookings[] = [
                    'name' => $row->activity['name'],
                    'date' => $row->activity['date']?->toDateString(),
                    'time' => $row->activity['time'],
                    'pax' => $row->activity['pax'],
                    'row' => $row->rowNumber,
                ];
            }
        }

        return $bookings;
    }

    /**
     * FR-017b: a status the dates make impossible is invalid, except a stale
     * "checked in" whose departure has passed, which old systems often leave
     * behind: it is recorded as checked out, with a warning (clarified
     * 2026-10-23).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function statusAgainstDates(array $draft, CarbonImmutable $today): ?array
    {
        $status = ReservationStatus::from($draft['attributes']['status']);
        $arrival = CarbonImmutable::parse($draft['attributes']['arrival_date']);
        $departure = CarbonImmutable::parse($draft['attributes']['departure_date']);
        $label = $draft['status_label'] ?? str_replace('_', ' ', $status->value);

        $problem = match (true) {
            $status === ReservationStatus::CHECKED_OUT && $departure->gt($today) => "A reservation can't be checked out before its departure ({$departure->toDateString()}).",
            $status === ReservationStatus::NO_SHOW && $arrival->gt($today) => "A reservation can't be a no-show before its arrival ({$arrival->toDateString()}).",
            $status === ReservationStatus::CHECKED_IN && $arrival->gt($today) => "A reservation can't be checked in before its arrival ({$arrival->toDateString()}).",
            default => null,
        };

        if ($problem) {
            $this->invalid($draft['rows'], ImportStage::VALIDATE, "{$problem} The file says \"{$label}\".", ['status', $status === ReservationStatus::NO_SHOW || $status === ReservationStatus::CHECKED_IN ? 'arrival_date' : 'departure_date']);

            return null;
        }

        if ($status === ReservationStatus::CHECKED_IN && $departure->lt($today)) {
            $draft['attributes']['status'] = ReservationStatus::CHECKED_OUT->value;
            $this->plan->warn($draft['position'], ImportWarning::STATUS_CORRECTED, "Status corrected: the file says checked in, but the departure ({$departure->toDateString()}) has passed, so it was imported as checked out.");
        }

        return $draft;
    }

    /**
     * The same person can be named on several rows and reservations. Each
     * guest mention is matched to the people named before it by phone,
     * email, old-system id or platform guest id; one match joins that person.
     * A mention whose details point at two different people makes its
     * reservation unresolved (FR-019), and registers nothing.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @return list<array<string, mixed>>
     */
    private function identifyPeople(array $drafts): array
    {
        $index = [];   // identity key => person id
        $firstRow = []; // person id => row first named on
        $next = 0;
        $kept = [];

        foreach ($drafts as $draft) {
            $assigned = [];
            $local = [];
            $conflict = null;

            foreach ($draft['mentions'] as $i => $mention) {
                $keys = self::identityKeys($mention);
                $people = array_values(array_unique(array_filter(array_map(fn (string $key) => $local[$key] ?? $index[$key] ?? null, $keys), fn ($person) => $person !== null)));

                if (count($people) > 1) {
                    $rows = array_map(fn (string $person) => $firstRow[$person], $people);
                    sort($rows);
                    $conflict = "The phone, email or guest id on row {$mention['row']} belong to different guests named on rows ".implode(' and ', array_unique($rows)).'. Make each guest\'s details match on every row.';

                    break;
                }

                $person = $people[0] ?? 'p'.$next++;
                $firstRow[$person] ??= $mention['row'];
                foreach ($keys as $key) {
                    $local[$key] = $person;
                }
                $assigned[$i] = $person;
            }

            if ($conflict !== null) {
                $this->plan->settle($draft['rows'], ImportRowOutcome::UNRESOLVED, ImportStage::MAP, $conflict, ['guest_phone', 'guest_email', 'guest_external_id']);

                continue;
            }

            $index = [...$index, ...$local];
            foreach ($assigned as $i => $person) {
                $draft['mentions'][$i]['person'] = $person;
            }
            $kept[] = $draft;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return list<string>
     */
    public static function identityKeys(array $guest): array
    {
        return array_values(array_filter([
            $guest['guest_id'] ? 'id:'.mb_strtolower($guest['guest_id']) : null,
            $guest['phone'] ? 'phone:'.$guest['phone'] : null,
            $guest['email'] ? 'email:'.$guest['email'] : null,
            $guest['external_id'] ? 'ext:'.mb_strtolower($guest['external_id']) : null,
        ]));
    }

    /**
     * One guest per person, exactly one primary (the one flagged, otherwise
     * the first named), and a party that holds them all. A party the file
     * does not give is one adult per named guest (FR-013a).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function guestsAndParty(array $draft): ?array
    {
        $guests = [];
        foreach ($draft['mentions'] as $mention) {
            $person = $mention['person'];
            if (! isset($guests[$person])) {
                $guests[$person] = ['person' => $person, 'row' => $mention['row'], 'is_primary' => false];
            }

            foreach (['first_name', 'last_name', 'phone', 'email', 'external_id', 'guest_id'] as $field) {
                $guests[$person][$field] ??= $mention[$field];
            }
            $guests[$person]['is_primary'] = $guests[$person]['is_primary'] || $mention['is_primary'] === true;
        }
        $guests = array_values($guests);

        if ($guests === []) {
            $this->invalid($draft['rows'], ImportStage::MAP, 'The reservation names no guest. Add guest_phone, guest_email or guest_external_id.', ['guest_phone']);

            return null;
        }

        $primaries = array_keys(array_filter($guests, fn (array $guest) => $guest['is_primary']));
        if (count($primaries) > 1) {
            $this->invalid($draft['rows'], ImportStage::MAP, 'More than one guest is marked primary. Mark only one.', ['is_primary']);

            return null;
        }
        $guests[$primaries[0] ?? 0]['is_primary'] = true;

        $attributes = $draft['attributes'];
        // Ages without a count say how many children there are.
        $children = $attributes['children'] ?? ($attributes['children_ages'] !== null ? count($attributes['children_ages']) : null);
        $adults = $attributes['adults'] ?? max(1, count($guests) - (int) $children);
        $children ??= 0;

        if (count($guests) > $adults + $children) {
            $this->invalid($draft['rows'], ImportStage::MAP, 'The reservation names '.count($guests)." guests but its party is {$adults} adults and {$children} children. Fix adults/children or the guest list.", ['adults', 'children']);

            return null;
        }

        $ages = $attributes['children_ages'];
        if ($ages !== null && count($ages) !== $children) {
            $this->invalid($draft['rows'], ImportStage::MAP, 'children_ages lists '.count($ages)." ages for {$children} children. Give one age per child, or leave it empty.", ['children_ages', 'children']);

            return null;
        }

        $draft['attributes']['adults'] = $adults;
        $draft['attributes']['children'] = $children;
        $draft['attributes']['children_ages'] = $ages ?? [];
        $draft['guests'] = $guests;
        unset($draft['mentions']);

        return $draft;
    }

    /**
     * FR-022: a reservation with no reference that repeats an earlier one in
     * the file (same primary guest, dates and room types) is a duplicate.
     *
     * @param  list<array<string, mixed>>  $drafts
     * @return list<array<string, mixed>>
     */
    private function withoutContentDuplicates(array $drafts): array
    {
        $seen = [];
        $kept = [];

        foreach ($drafts as $draft) {
            if ($draft['reference'] !== null) {
                $kept[] = $draft;

                continue;
            }

            $key = self::contentKey($draft);
            if (isset($seen[$key])) {
                $this->plan->settle($draft['rows'], ImportRowOutcome::DUPLICATE, ImportStage::MAP, "This repeats the reservation on row {$seen[$key]} (same guest, dates and room types).");

                continue;
            }

            $seen[$key] = $draft['position'];
            $kept[] = $draft;
        }

        return $kept;
    }

    /** @param array<string, mixed> $draft */
    private static function contentKey(array $draft): string
    {
        $primary = collect($draft['guests'])->firstWhere('is_primary', true)['person'];
        $types = array_map(fn (array $unit) => mb_strtolower((string) $unit['room_type']), $draft['units']);
        sort($types);

        return implode('|', [$primary, $draft['attributes']['arrival_date'], $draft['attributes']['departure_date'], implode(',', $types)]);
    }

    /**
     * FR-012a inside the file: in file order, a room already held on an
     * overlapping night by an earlier live reservation is left off the later
     * line, with a warning. A guest in house with no room gets a "no room
     * assigned" warning (FR-017a).
     *
     * @param  list<array<string, mixed>>  $drafts
     * @return list<array<string, mixed>>
     */
    private function withoutRoomClashes(array $drafts, CarbonImmutable $today): array
    {
        $held = [];

        foreach ($drafts as &$draft) {
            $status = ReservationStatus::from($draft['attributes']['status']);
            $live = in_array($status, ReservationStatus::holdingInventory(), true);
            $arrival = $draft['attributes']['arrival_date'];
            $departure = $draft['attributes']['departure_date'];
            $label = $draft['reference'] ?? "row {$draft['position']}";

            foreach ($draft['units'] as &$unit) {
                $unit['assign'] = $unit['room_number'] !== null;
                if (! $live || $unit['room_number'] === null) {
                    continue;
                }

                $key = mb_strtolower($unit['room_number']);
                $clash = collect($held[$key] ?? [])->first(fn (array $other) => $other['arrival'] < $departure && $other['departure'] > $arrival);

                if ($clash) {
                    $unit['assign'] = false;
                    $this->plan->warn($unit['row'], ImportWarning::ROOM_CLASH, "Room {$unit['room_number']} is booked for {$clash['label']} on overlapping nights, so this line was imported without a room.");

                    continue;
                }

                $held[$key][] = ['arrival' => $arrival, 'departure' => $departure, 'label' => $label];
            }
            unset($unit);

            if ($status === ReservationStatus::CHECKED_IN) {
                foreach ($draft['units'] as $unit) {
                    if (! $unit['assign']) {
                        $this->plan->warn($unit['row'], ImportWarning::NO_ROOM_ASSIGNED, 'The guest is in house but no room is assigned to this line; assign one at the front desk.');
                    }
                }
            }
        }
        unset($draft);

        return $drafts;
    }

    /**
     * @param  list<int>  $rowNumbers
     * @param  list<string>  $fields
     */
    private function invalid(array $rowNumbers, ImportStage $stage, string $reason, array $fields): void
    {
        $this->plan->settle($rowNumbers, ImportRowOutcome::INVALID, $stage, $reason, $fields);
    }

    /**
     * In a reservation of several rows, says which row the problem is on.
     *
     * @param  non-empty-list<NormalizedRow>  $group
     */
    private function withRow(array $group, string $field, string $message): string
    {
        if (count($group) === 1) {
            return $message;
        }

        $row = collect($group)->first(fn (NormalizedRow $row) => isset($row->errors[$field]));

        return "Row {$row->rowNumber}: {$message}";
    }
}
