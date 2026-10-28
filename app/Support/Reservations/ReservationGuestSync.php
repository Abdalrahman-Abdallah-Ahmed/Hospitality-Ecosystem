<?php

namespace App\Support\Reservations;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Stay;
use App\Services\GuestIdentityService;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReservationGuestSync
{
    /**
     * Replaces the reservation's guest list with `$members`. Callers run it
     * inside their reservation transaction; the reservation row is locked
     * first, so two edits of one list apply one after the other and the later
     * complete list wins, never leaving zero or two primaries.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public function sync(Reservation $reservation, array $members, ?string $expectedPrimaryId = null): void
    {
        $members = $this->normalized($members);

        Reservation::withoutGlobalScope('hotel')->withTrashed()->whereKey($reservation->id)->lockForUpdate()->first();

        $members = $this->resolvedMembers($reservation, $members);
        $primary = $this->validateParty($reservation, $members, $expectedPrimaryId);
        $this->syncMemberships($reservation, $members);
        $this->syncPrimaryGuest($reservation, $primary);
    }

    /**
     * The guest `$members` marks primary, found or created by the same rules
     * as sync(), so a new reservation can point at a primary named only by
     * contact details. Callers run it inside their reservation transaction,
     * so a guest it creates goes away with a reservation that fails.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public function resolvePrimary(string $hotelId, array $members): Guest
    {
        $members = $this->normalized($members);
        $primaries = array_keys(array_filter($members, fn (array $member, int $index) => $this->primaryFlag($member, $index), ARRAY_FILTER_USE_BOTH));

        if (count($primaries) !== 1) {
            throw ValidationException::withMessages(['guests' => 'A reservation must have exactly one primary guest.']);
        }

        return $this->resolveMember(new Reservation(['hotel_id' => $hotelId]), $members[$primaries[0]], $primaries[0])['guest'];
    }

    /**
     * A party edit that leaves the guest list alone must still fit it: the
     * named guests can never outnumber adults + children (FR-006).
     *
     * @throws ValidationException
     */
    public function assertListFitsParty(Reservation $reservation): void
    {
        $named = $reservation->reservationGuests()->count();

        if ($named > (int) ($reservation->adults ?? 1) + (int) ($reservation->children ?? 0)) {
            throw ValidationException::withMessages(['guests' => 'The guest list cannot exceed the reservation party size.']);
        }
    }

    /** @param list<array<string, mixed>> $members @return list<array<string, mixed>> */
    private function normalized(array $members): array
    {
        // validated() writes entries rule by rule, so an entry without the
        // first rule's key (guest_id) can land after a later one: [1, 0].
        if (collect(array_keys($members))->every(fn ($key) => is_int($key))) {
            ksort($members);
        }

        if (! array_is_list($members) || collect($members)->contains(fn ($member) => ! is_array($member))) {
            throw ValidationException::withMessages(['guests' => 'Guests must be supplied as a list of guest objects.']);
        }

        if (count($members) === 1 && ! array_key_exists('is_primary', $members[0])) {
            $members[0]['is_primary'] = true;
        }

        return $members;
    }

    /** @param list<array<string, mixed>> $members @return list<array{guest: Guest, is_primary: bool, index: int}> */
    private function resolvedMembers(Reservation $reservation, array $members): array
    {
        return array_map(
            fn (array $member, int $index) => $this->resolveMember($reservation, $member, $index),
            $members,
            array_keys($members),
        );
    }

    /** @param list<array{guest: Guest, is_primary: bool, index: int}> $members */
    private function validateParty(Reservation $reservation, array $members, ?string $expectedPrimaryId): Guest
    {
        if (count($members) === 0 || collect($members)->where('is_primary', true)->count() !== 1) {
            throw ValidationException::withMessages(['guests' => 'A reservation must have exactly one primary guest.']);
        }

        $duplicate = collect($members)->groupBy(fn (array $member) => $member['guest']->id)->first(fn ($group) => $group->count() > 1);
        if ($duplicate) {
            throw ValidationException::withMessages(['guests.'.$duplicate->first()['index'].'.guest_id' => 'A guest can appear only once on a reservation.']);
        }

        if (count($members) > (int) ($reservation->adults ?? 1) + (int) ($reservation->children ?? 0)) {
            throw ValidationException::withMessages(['guests' => 'The guest list cannot exceed the reservation party size.']);
        }

        $primary = collect($members)->firstWhere('is_primary', true)['guest'];
        if ($expectedPrimaryId !== null && $primary->id !== $expectedPrimaryId) {
            throw ValidationException::withMessages(['guests' => 'The primary guest must match guest_id.']);
        }

        return $primary;
    }

    /** @param array<string, mixed> $member */
    private function contactMatches(Guest $guest, array $member): bool
    {
        if (! empty($member['email']) && mb_strtolower(trim($guest->email ?? '')) !== mb_strtolower(trim($member['email']))) {
            return false;
        }

        return empty($member['phone_number'])
            || PhoneNumber::digits($guest->phone_number) === PhoneNumber::digits($member['phone_number']);
    }

    /** @param list<array{guest: Guest, is_primary: bool, index: int}> $members */
    private function syncMemberships(Reservation $reservation, array $members): void
    {
        $desired = collect($members)->keyBy(fn (array $member) => $member['guest']->id);
        $existing = ReservationGuest::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->get()
            ->keyBy('guest_id');

        $this->retireRemovedMembers($existing, $desired);
        $this->saveDesiredMembers($reservation, $members, $existing);
    }

    private function syncPrimaryGuest(Reservation $reservation, Guest $primary): void
    {
        if ($reservation->guest_id === $primary->id) {
            return;
        }

        $reservation->update(['guest_id' => $primary->id]);
        Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)
            ->where('status', '!=', 'departed')
            ->get()
            ->each(fn (Stay $stay) => $stay->update(['guest_id' => $primary->id]));
    }

    /**
     * @param  Collection<string, ReservationGuest>  $existing
     * @param  Collection<string, array{guest: Guest, is_primary: bool, index: int}>  $desired
     */
    private function retireRemovedMembers(Collection $existing, Collection $desired): void
    {
        foreach ($existing as $guestId => $link) {
            if (! $desired->has($guestId)) {
                $link->delete();
            } elseif ($link->is_primary && ! $desired[$guestId]['is_primary']) {
                $link->update(['is_primary' => false]);
            }
        }
    }

    /**
     * @param  list<array{guest: Guest, is_primary: bool, index: int}>  $members
     * @param  Collection<string, ReservationGuest>  $existing
     */
    private function saveDesiredMembers(Reservation $reservation, array $members, Collection $existing): void
    {
        foreach ($members as $member) {
            $link = $existing->get($member['guest']->id);
            if ($link) {
                if ($link->is_primary !== $member['is_primary']) {
                    $link->update(['is_primary' => $member['is_primary']]);
                }

                continue;
            }

            $this->restoreOrCreateMember($reservation, $member);
        }
    }

    /** @param array{guest: Guest, is_primary: bool, index: int} $member */
    private function restoreOrCreateMember(Reservation $reservation, array $member): void
    {
        $link = ReservationGuest::withTrashed()->firstOrNew([
            'reservation_id' => $reservation->id,
            'guest_id' => $member['guest']->id,
        ]);
        $link->fill(['hotel_id' => $reservation->hotel_id, 'is_primary' => $member['is_primary']]);

        if ($link->trashed()) {
            $link->restore();
        }

        $link->save();
    }

    /** @param array<string, mixed> $member @return array{guest: Guest, is_primary: bool, index: int} */
    private function resolveMember(Reservation $reservation, array $member, int $index): array
    {
        $isPrimary = $this->primaryFlag($member, $index);
        $guest = $this->findGuest($reservation, $member, $index);

        if (isset($member['guest_id']) && ! $guest) {
            throw ValidationException::withMessages(["guests.{$index}.guest_id" => 'The selected guest is unavailable at this hotel.']);
        }

        if ($guest && $guest->hotel_id !== $reservation->hotel_id) {
            throw ValidationException::withMessages(["guests.{$index}.guest_id" => 'The selected guest is unavailable at this hotel.']);
        }

        if ($guest && isset($member['guest_id']) && ! $this->contactMatches($guest, $member)) {
            throw ValidationException::withMessages(["guests.{$index}" => 'The supplied contact details do not match the selected guest.']);
        }

        $guest ??= $this->createGuest($reservation, $member, $index);
        if ($guest->trashed()) {
            $guest->restore();
        }

        return ['guest' => $guest, 'is_primary' => $isPrimary, 'index' => $index];
    }

    /** @param array<string, mixed> $member */
    private function findGuest(Reservation $reservation, array $member, int $index): ?Guest
    {
        try {
            return isset($member['guest_id'])
                ? Guest::withoutGlobalScope('hotel')->find($member['guest_id'])
                : app(GuestIdentityService::class)->findForReservation($reservation->hotel_id, $member['email'] ?? null, $member['phone_number'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->flatMap(fn (array $messages) => array_fill_keys(["guests.{$index}"], $messages))
                ->all());
        }
    }

    /** @param array<string, mixed> $member */
    private function createGuest(Reservation $reservation, array $member, int $index): Guest
    {
        if (empty($member['phone_number']) && empty($member['email'])) {
            throw ValidationException::withMessages(["guests.{$index}" => 'A new guest needs a phone number or email.']);
        }

        return Guest::create([
            'hotel_id' => $reservation->hotel_id,
            ...collect($member)->only(['first_name', 'last_name', 'email', 'phone_number'])->all(),
        ]);
    }

    /** @param array<string, mixed> $member */
    private function primaryFlag(array $member, int $index): bool
    {
        $isPrimary = $member['is_primary'] ?? false;
        if (! in_array($isPrimary, [true, false, 0, 1, '0', '1'], true)) {
            throw ValidationException::withMessages(["guests.{$index}.is_primary" => 'The primary marker must be boolean.']);
        }

        return (bool) $isPrimary;
    }
}
