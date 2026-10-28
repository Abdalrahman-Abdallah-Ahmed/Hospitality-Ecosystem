<?php

namespace App\Support\Reservations;

use App\Enums\CleaningReason;
use App\Enums\HousekeepingCause;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\AvailabilityService;
use App\Services\GuestIdentityService;
use App\Services\HousekeepingService;
use App\Services\StayService;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one domain operation that writes reservations and their room lines,
 * used by ReservationController, CreateReservationTool and the
 * reservation import (ReservationImportWriter), so the line rules
 * (ReservationRoomSync), the soft-delete-restore and the room-occupancy
 * rules stay in one place.
 */
class ReservationCreator
{
    /**
     * Guests are matched by phone number within a hotel; a trashed match is
     * restored rather than duplicated, since phone_number is how repeat
     * guests are recognized across channels (WhatsApp, imports, etc).
     *
     * Matching goes through GuestIdentityService's normalised phone hash,
     * not a raw string comparison — the callers of this method (WhatsApp,
     * CSV imports) are exactly where the same real number shows up
     * formatted differently each time (e.g. "+20 115 179 3758" from a
     * spreadsheet vs. "201151793758" from a WhatsApp webhook), so an exact
     * match would silently create a duplicate guest for the same person.
     * An unambiguous same-hotel email is used only when the phone finds nobody.
     * fill in a new guest's defaults) — this method's whole contract is
     * phone and email conflicts are rejected rather than merging profiles.
     */
    public static function findOrCreateGuest(string $hotelId, array $attributes): Guest
    {
        $guest = app(GuestIdentityService::class)->findForReservation($hotelId, $attributes['email'] ?? null, $attributes['phone_number'] ?? null);

        if (! $guest) {
            $guest = Guest::create([
                'hotel_id' => $hotelId,
                'phone_number' => $attributes['phone_number'],
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'email' => $attributes['email'] ?? null,
            ]);
        }

        if ($guest->trashed()) {
            $guest->restore();
        }

        return $guest;
    }

    /**
     * Whether a live reservation at this hotel already uses this code.
     * Codes are unique per hotel — two properties may both issue "RES-1001" —
     * and that composite index is beyond what the schema-derived validation
     * rules can check. A soft-deleted match is not counted: create() restores
     * it instead.
     */
    public static function isReservationIdInUse(string $hotelId, string $reservationId): bool
    {
        return Reservation::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotelId)
            ->where('reservation_id', $reservationId)
            ->exists();
    }

    /**
     * Creates a reservation with its room lines — one per booked unit, see
     * ReservationRoomSync::expand() for the `$rooms` shape — in one
     * transaction, so a failure never leaves a reservation without its rooms.
     *
     * A trashed reservation is not visible through normal queries, but its
     * (hotel_id, reservation_id) row still exists, so blindly creating would
     * throw a duplicate-key error. Restore and update it instead; its old
     * lines are cancelled and replaced by the new ones.
     *
     * The lookup names the hotel explicitly. Queued callers run with no
     * tenant scope, and matching on the code alone would restore — and
     * overwrite — another hotel's reservation that happens to share it.
     *
     * `$fromImport` is for the reservation import only: legacy data is
     * recorded as it is rather than rejected, so the party-capacity check and
     * the availability guard are skipped and a room type the hotel has since
     * deactivated is accepted.
     *
     * The booked room types are locked first (AvailabilityService::lockTypes()),
     * so two bookings for the last room of a type are checked one after the
     * other. `$overbookOverride` saves a booking that oversells anyway; the
     * caller has already checked the actor may.
     *
     * @param  array<int, array<string, mixed>>  $rooms
     */
    public static function create(array $attributes, array $rooms, bool $capacityOverride = false, bool $fromImport = false, bool $overbookOverride = false): Reservation
    {
        $guests = $attributes['guests'] ?? null;
        unset($attributes['room_id'], $attributes['guests']);

        $units = ReservationRoomSync::expand($rooms);
        ReservationRoomSync::validateNewUnits($attributes['hotel_id'], $units, allowInactive: $fromImport);

        self::validateChildrenAges($attributes, $fromImport && empty($attributes['children_ages']));

        if (! $fromImport && isset($attributes['status'])) {
            ReservationLifecycle::assertCreate(self::statusOf($attributes['status']));
        }

        return DB::transaction(function () use ($attributes, $units, $capacityOverride, $fromImport, $overbookOverride, $guests) {
            // A primary named only by contact details: found or created here,
            // so it is rolled back with a reservation that fails.
            if (empty($attributes['guest_id']) && $guests !== null) {
                $attributes['guest_id'] = app(ReservationGuestSync::class)->resolvePrimary($attributes['hotel_id'], $guests)->id;
            }

            $availability = app(AvailabilityService::class);
            $availability->lockTypes($attributes['hotel_id'], array_column($units, 'room_type_id'));

            $reservation = Reservation::onlyTrashed()
                ->where('hotel_id', $attributes['hotel_id'])
                ->where('reservation_id', $attributes['reservation_id'])
                ->first();

            $previousRoomIds = [];

            if ($reservation) {
                $previousRoomIds = self::roomIdsOf($reservation);
                $reservation->restore();
                $reservation->update($attributes);
                self::cancelLines($reservation->reservationRooms()->active()->get());
            } else {
                $reservation = Reservation::create($attributes);
            }

            app(ReservationGuestSync::class)->sync(
                $reservation,
                $guests ?? [['guest_id' => $reservation->guest_id, 'is_primary' => true]],
                $attributes['guest_id'] ?? null,
            );

            foreach ($units as $unit) {
                self::addLine($reservation, $unit);
            }

            if (! $fromImport) {
                self::checkCapacity($reservation, $capacityOverride);

                // A restored reservation's old lines were just cancelled, so
                // everything it holds now is new: nothing to subtract.
                $availability->guard($reservation, [], $overbookOverride);
            }

            self::syncStays($reservation, recordStatus: true);
            self::syncRoomOccupancy([...$previousRoomIds, ...self::roomIdsOf($reservation)]);

            return $reservation;
        });
    }

    /**
     * Updates a reservation and, when `$rooms` is given, its lines, in one
     * transaction. `$rooms` is the desired list of live lines (see
     * ReservationRoomSync::diff()); null leaves the lines alone.
     *
     * Cancelling the reservation cancels every live line and marks them as
     * cancelled with it. Bringing a cancelled reservation back (any other
     * status) restores those lines, unless `$rooms` says which lines it
     * should have instead.
     *
     * Only what the change adds (more lines, new or longer dates) is checked
     * against availability: the reservation's footprint from before the
     * change is subtracted, so its own lines never count against it.
     *
     * @param  array<int, array<string, mixed>>|null  $rooms
     */
    public static function update(Reservation $reservation, array $attributes, ?array $rooms, bool $capacityOverride = false, bool $overbookOverride = false): Reservation
    {
        $guests = $attributes['guests'] ?? null;
        unset($attributes['room_id'], $attributes['guests']);

        if ($reservation->status === ReservationStatus::NO_SHOW) {
            self::assertNoShowEditable($reservation, $attributes, $rooms);
        }

        if (isset($attributes['status'])) {
            ReservationLifecycle::assertEdit($reservation, self::statusOf($attributes['status']));
        }

        if ($guests === null && isset($attributes['guest_id']) && $attributes['guest_id'] !== $reservation->guest_id) {
            $guests = self::membersWithPrimary($reservation, $attributes['guest_id']);
        }

        self::validateChildrenAges([...$reservation->only(['children', 'children_ages']), ...$attributes], ! array_key_exists('children', $attributes) && ! array_key_exists('children_ages', $attributes));

        $statusBefore = $reservation->status;
        $statusAfter = isset($attributes['status']) ? self::statusOf($attributes['status']) : $statusBefore;
        $reactivating = $statusBefore === ReservationStatus::CANCELLED && $statusAfter !== ReservationStatus::CANCELLED;

        $plan = $rooms !== null
            ? ReservationRoomSync::diff($reservation, $rooms, $statusBefore, $reactivating)
            : null;

        return DB::transaction(function () use ($reservation, $attributes, $plan, $capacityOverride, $reactivating, $overbookOverride, $guests, $statusBefore) {
            // Every line's type, cancelled ones too: reactivating the
            // reservation brings its cancelled lines back into inventory.
            $availability = app(AvailabilityService::class);
            $availability->lockTypes($reservation->hotel_id, [
                ...$reservation->reservationRooms()->pluck('room_type_id')->all(),
                ...array_column($plan['create'] ?? [], 'room_type_id'),
            ]);
            // The status the checks above read, confirmed under the row lock:
            // a no-show (or check-in) committed meanwhile changes which edits
            // are allowed, so this edit stops rather than write over it.
            $lockedStatus = Reservation::withoutGlobalScope('hotel')->withTrashed()->whereKey($reservation->id)->lockForUpdate()->value('status');

            if ($lockedStatus !== $statusBefore) {
                throw ValidationException::withMessages(['status' => "The reservation changed while it was being edited (it is now {$lockedStatus->value}); reload it and try again."]);
            }

            $footprintBefore = $availability->footprint($reservation);

            $roomIdsBefore = self::roomIdsOf($reservation);
            $vacatedRooms = self::guardInHouse($reservation, $attributes, $plan);

            $reservation->update($attributes);
            $partyChanged = $reservation->wasChanged(['adults', 'children']);

            if ($guests !== null) {
                app(ReservationGuestSync::class)->sync($reservation, $guests, $attributes['guest_id'] ?? null);
            } elseif ($partyChanged) {
                app(ReservationGuestSync::class)->assertListFitsParty($reservation);
            }

            if ($plan) {
                self::applyPlan($reservation, $plan);
            } elseif ($reactivating) {
                self::reinstateLines($reservation->reservationRooms()->where('cancelled_with_reservation', true)->get());
            }

            if ($reservation->status === ReservationStatus::CANCELLED) {
                self::cancelLines($reservation->reservationRooms()->active()->get(), withReservation: true);
            }

            if (($plan || $partyChanged || $reactivating) && $reservation->status !== ReservationStatus::CANCELLED) {
                self::checkCapacity($reservation, $capacityOverride);
            }

            $availability->guard($reservation, $footprintBefore, $overbookOverride);

            self::syncStays($reservation, recordStatus: $reservation->wasChanged('status'));
            self::syncRoomOccupancy([...$roomIdsBefore, ...self::roomIdsOf($reservation)]);
            self::markDirty($vacatedRooms);

            return $reservation;
        });
    }

    /**
     * Every room ever put on this reservation's lines, cancelled ones included,
     * so an occupancy sync after a change also reaches the rooms it released.
     *
     * @return array<int, string>
     */
    public static function roomIdsOf(Reservation $reservation): array
    {
        return ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->whereNotNull('room_id')
            ->pluck('room_id')
            ->all();
    }

    /**
     * @param  array{room_type_id: mixed, room_id: mixed}  $unit
     */
    private static function addLine(Reservation $reservation, array $unit): ReservationRoom
    {
        return ReservationRoom::create([
            'hotel_id' => $reservation->hotel_id,
            'reservation_id' => $reservation->id,
            'room_type_id' => $unit['room_type_id'],
            'room_id' => $unit['room_id'],
            'status' => ReservationRoomStatus::RESERVED,
        ]);
    }

    /**
     * Writes a planned line update in an order the database accepts. One
     * room may sit on only one live line of a reservation, and the unique
     * index is checked after every row, so: removed lines give their rooms
     * up first, every moving line then lets go of its old room, and only
     * then do moves, reinstated lines and new lines take theirs. Swapping
     * two lines' rooms, or moving into a room a removed line held, works.
     *
     * @param  array{moves: array<string, string|null>, create: array<int, array<string, mixed>>, cancel: iterable<ReservationRoom>, reinstate: array<int, string>}  $plan
     */
    private static function applyPlan(Reservation $reservation, array $plan): void
    {
        self::cancelLines($plan['cancel']);

        $lines = ReservationRoom::withoutGlobalScope('hotel')
            ->whereIn('id', [...array_keys($plan['moves']), ...$plan['reinstate']])
            ->get()
            ->keyBy('id');

        // Let go of old rooms quietly; the audited change is the final one.
        foreach (array_keys($plan['moves']) as $lineId) {
            if ($lines[$lineId]->room_id !== null) {
                ReservationRoom::withoutGlobalScope('hotel')->whereKey($lineId)->update(['room_id' => null]);
            }
        }

        self::reinstateLines($lines->only($plan['reinstate']));

        foreach ($plan['moves'] as $lineId => $roomId) {
            $lines[$lineId]->update(['room_id' => $roomId]);
        }

        foreach ($plan['create'] as $unit) {
            self::addLine($reservation, $unit);
        }
    }

    /**
     * Cancelled through the model, one by one, so each is audited.
     * `$withReservation` marks lines cancelled because the whole
     * reservation was, so bringing it back can restore them.
     *
     * @param  iterable<ReservationRoom>  $lines
     */
    private static function cancelLines(iterable $lines, bool $withReservation = false): void
    {
        foreach ($lines as $line) {
            $line->update([
                'status' => ReservationRoomStatus::CANCELLED,
                'cancelled_with_reservation' => $withReservation,
            ]);
        }
    }

    /**
     * @param  iterable<ReservationRoom>  $lines
     */
    private static function reinstateLines(iterable $lines): void
    {
        foreach ($lines as $line) {
            $line->update([
                'status' => ReservationRoomStatus::RESERVED,
                'cancelled_with_reservation' => false,
            ]);
        }
    }

    /**
     * Skipped for a reservation with no live lines: only legacy rows written
     * outside this class can be in that state, and there is nothing to
     * measure the party against.
     */
    private static function checkCapacity(Reservation $reservation, bool $override): void
    {
        $typeIds = ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->active()
            ->pluck('room_type_id');

        if ($typeIds->isEmpty()) {
            return;
        }

        $types = RoomType::withoutGlobalScope('hotel')->withTrashed()
            ->whereIn('id', $typeIds->unique())
            ->get()
            ->keyBy('id');

        $lineTypes = $typeIds->map(fn (string $id) => $types[$id]);
        $adults = (int) ($reservation->adults ?? 1);
        $children = (int) ($reservation->children ?? 0);

        if (ReservationRoomSync::assertCapacity($adults, $children, $lineTypes, $override)) {
            EventLogger::record($reservation, 'capacity_overridden', changes: [
                'adults' => $adults,
                'children' => $children,
                'max_occupancy' => $lineTypes->sum('max_occupancy'),
                'adult_capacity' => $lineTypes->sum('adult_capacity'),
            ]);
        }
    }

    private static function statusOf(mixed $status): ReservationStatus
    {
        return $status instanceof ReservationStatus ? $status : ReservationStatus::from($status);
    }

    /**
     * While a reservation is a no-show, only its guest list and special
     * requests may change (SPEC-012 FR-010a). Everything else waits for the
     * no-show to be undone, where availability is checked again. An allow-
     * list, so a field added later is locked by default; sending a field
     * with the value it already has is not a change.
     *
     * @param  array<string, mixed>  $attributes  without `guests`
     * @param  array<int, array<string, mixed>>|null  $rooms
     *
     * @throws ValidationException
     */
    private static function assertNoShowEditable(Reservation $reservation, array $attributes, ?array $rooms): void
    {
        $probe = clone $reservation;
        $probe->fill($attributes);

        // Status is ReservationLifecycle's: it names the undo endpoint.
        $locked = array_diff(array_keys($probe->getDirty()), ['special_requests', 'status']);

        if ($rooms !== null) {
            $locked[] = 'rooms';
        }

        if ($locked !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($locked), 'Undo the no-show first.'));
        }
    }

    /** @param array<string, mixed> $attributes */
    private static function validateChildrenAges(array $attributes, bool $legacyUnchanged): void
    {
        if ($legacyUnchanged) {
            return;
        }

        $ages = $attributes['children_ages'] ?? [];
        if (! is_array($ages) || count($ages) !== (int) ($attributes['children'] ?? 0)) {
            throw ValidationException::withMessages(['children_ages' => 'Provide exactly one age for every child.']);
        }
        foreach ($ages as $age) {
            if (! is_int($age) || $age < 0 || $age > 17) {
                throw ValidationException::withMessages(['children_ages' => 'Each child age must be an integer from 0 to 17.']);
            }
        }
    }

    /** @return list<array{guest_id: string, is_primary: bool}> */
    private static function membersWithPrimary(Reservation $reservation, string $primaryGuestId): array
    {
        $members = $reservation->reservationGuests()
            ->get()
            ->map(fn ($member) => [
                'guest_id' => $member->guest_id,
                'is_primary' => $member->guest_id === $primaryGuestId,
            ])
            ->all();

        if (! collect($members)->contains(fn (array $member) => $member['guest_id'] === $primaryGuestId)) {
            $members[] = ['guest_id' => $primaryGuestId, 'is_primary' => true];
        }

        return $members;
    }

    /**
     * Keeps one stay per line in step with the reservation (StayService::
     * syncForReservation()).
     *
     * `$recordStatus` is for callers that record what already happened — the
     * reservation import, fixtures — when they set a status: checked in puts
     * the live lines' stays in the house, checked out makes them departed,
     * with no room checks and no cleaning task. Front-desk check-in and
     * check-out never come through here; they are StayLifecycleService, and
     * the HTTP and AI entry points hand those statuses to it instead.
     *
     * Cancelled needs no flag: the sync cancels the stays of cancelled lines,
     * and this also cancels a line-less legacy stay. A no-show recorded this
     * way (import, fixtures) marks the expected stays no-show; staff mark
     * no-shows through NoShowService instead.
     */
    public static function syncStays(Reservation $reservation, bool $recordStatus = false): void
    {
        $stayService = app(StayService::class);
        $stays = $stayService->syncForReservation($reservation)
            ->reject(fn (Stay $stay) => $stay->status === StayStatus::CANCELLED);

        foreach ($stays as $stay) {
            match (true) {
                $reservation->status === ReservationStatus::CANCELLED && $stay->status === StayStatus::EXPECTED => $stayService->markCancelled($stay),
                ! $recordStatus => null,
                $reservation->status === ReservationStatus::CHECKED_IN && $stay->status === StayStatus::EXPECTED => $stayService->checkIn($stay),
                $reservation->status === ReservationStatus::CHECKED_OUT && in_array($stay->status, [StayStatus::EXPECTED, StayStatus::IN_HOUSE], true) => $stayService->checkOut($stay),
                $reservation->status === ReservationStatus::NO_SHOW && $stay->status === StayStatus::EXPECTED => $stayService->markNoShow($stay),
                default => null,
            };
        }
    }

    /**
     * Deletes a reservation and its stays in one go (FR-021: not while a
     * guest is in the house, which would leave an occupied room nobody is in).
     *
     * @throws ValidationException
     */
    public static function delete(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $locked = Reservation::withoutGlobalScope('hotel')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (self::inHouseStays($locked)->isNotEmpty()) {
                throw ValidationException::withMessages(['reservation' => 'Check out the in-house rooms first.']);
            }

            Stay::withoutGlobalScope('hotel')->where('reservation_id', $locked->id)->get()->each->delete();
            $locked->delete();
        });
    }

    /**
     * Rejects an edit that would strand a guest in the house (FR-021): a
     * status change away from checked in, or removing or cancelling a line
     * whose guest is in. Returns the rooms that in-house guests are moving
     * out of, which are left dirty.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $plan
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function guardInHouse(Reservation $reservation, array $attributes, ?array $plan): array
    {
        $inHouse = self::inHouseStays($reservation)->keyBy('reservation_room_id');

        if ($inHouse->isEmpty()) {
            return [];
        }

        $status = $attributes['status'] ?? null;
        $status = $status instanceof ReservationStatus ? $status : ($status !== null ? ReservationStatus::from($status) : null);

        if ($status !== null && $status !== ReservationStatus::CHECKED_IN) {
            throw ValidationException::withMessages(['status' => 'Check out the in-house rooms first.']);
        }

        foreach ($plan['cancel'] ?? [] as $line) {
            if ($inHouse->has($line->id)) {
                $room = $line->room()->withoutGlobalScope('hotel')->value('room_number') ?? 'unassigned';

                throw ValidationException::withMessages(['rooms' => "Room {$room} is checked in; check it out first."]);
            }
        }

        $vacated = [];

        foreach ($plan['moves'] ?? [] as $lineId => $roomId) {
            $stay = $inHouse->get($lineId);

            if ($stay && $stay->room_id && $stay->room_id !== $roomId) {
                $vacated[] = $stay->room_id;
            }
        }

        return $vacated;
    }

    /**
     * @return Collection<int, Stay>
     */
    private static function inHouseStays(Reservation $reservation): Collection
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->get();
    }

    /**
     * Rooms a guest just moved out of need servicing: dirty, with a cleaning
     * task, as on check-out.
     *
     * @param  list<string>  $roomIds
     */
    private static function markDirty(array $roomIds): void
    {
        $housekeeping = app(HousekeepingService::class);

        foreach (Room::withoutGlobalScope('hotel')->whereIn('id', $roomIds)->orderBy('id')->get() as $room) {
            $housekeeping->roomNeedsCleaning($room, CleaningReason::CHECK_OUT, HousekeepingCause::CHECK_OUT);
        }
    }

    /**
     * Keeps the status of each given room in step with who is physically
     * there. Pass every room an operation touched — the rooms its lines hold
     * now and the ones it just released or moved out of. Call after
     * syncStays(), which it reads.
     *
     * @param  array<int, string|null>  $roomIds
     */
    public static function syncRoomOccupancy(array $roomIds): void
    {
        foreach (array_unique(array_filter($roomIds)) as $roomId) {
            self::syncRoomStatus($roomId);
        }
    }

    /**
     * A room is occupied while any in-house guest is in it, and released back
     * to available once none is. Derived from everyone in the room rather
     * than from one reservation, so booking a room for next week cannot
     * release it while tonight's guest is still in it.
     *
     * Only an occupied room is ever released. An out-of-order room is never
     * touched: only MaintenanceService takes a room out of order or returns
     * it to service (FR-016, FR-020).
     */
    private static function syncRoomStatus(string $roomId): void
    {
        $someoneInHouse = DB::query()
            ->fromSub(ReservationRoom::inHouseRoomIds(), 'in_house')
            ->where('room_id', $roomId)
            ->exists();

        // Through the model, so the change is audited (room.updated).
        $room = Room::withoutGlobalScope('hotel')->find($roomId);

        if (! $room || $room->isOutOfOrder()) {
            return;
        }

        if ($someoneInHouse) {
            $room->update(['status' => RoomStatusesEnum::OCCUPIED]);

            return;
        }

        if ($room->status === RoomStatusesEnum::OCCUPIED) {
            $room->update(['status' => RoomStatusesEnum::AVAILABLE]);
        }
    }
}
