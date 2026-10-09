<?php

namespace App\Services\Guests;

use App\Exceptions\DomainRuleException;
use App\Models\Guest;
use App\Models\Hotel;
use App\Services\GuestIdentityService;

/**
 * Records and updates guests for the staff API and the Admin AI alike, so
 * both apply the same duplicate rules (SPEC-055 R4). A duplicate guest splits
 * their stays, bookings and history in two without anything reporting an
 * error, so registering an existing person returns their record instead.
 */
class GuestRegistrar
{
    public function __construct(private readonly GuestIdentityService $identity) {}

    /**
     * Create a guest, or return the existing record for the same person.
     *
     * @param  array<string, mixed>  $attributes  validated, without hotel_id
     * @return array{guest: Guest, created: bool}
     *
     * @throws DomainRuleException when an active guest already holds this channel and external id
     */
    public function register(Hotel $hotel, array $attributes): array
    {
        // The (hotel_id, channel, external_id) unique index is composite, so the
        // generic schema-derived rules can't validate it. A trashed guest's row
        // still occupies that key, so look for it and restore instead of
        // letting Guest::create() hit a duplicate-key error.
        if (! empty($attributes['channel']) && ! empty($attributes['external_id'])) {
            $existing = Guest::withTrashed()
                ->where('hotel_id', $hotel->id)
                ->where('channel', $attributes['channel'])
                ->where('external_id', $attributes['external_id'])
                ->first();

            if ($existing && ! $existing->trashed()) {
                throw new DomainRuleException('A guest with this channel and external id already exists.', 422);
            }

            if ($existing) {
                // Before resurrecting this trashed row, make sure a different,
                // already-active guest isn't the same real person by email/phone
                // — restoring here regardless would recreate the exact
                // duplicate this whole check exists to prevent.
                $activeIdentityMatch = $this->identity->findExistingGuest(
                    $hotel->id,
                    $attributes['email'] ?? null,
                    $attributes['phone_number'] ?? null,
                );

                if ($activeIdentityMatch && ! $activeIdentityMatch->trashed() && $activeIdentityMatch->isNot($existing)) {
                    return ['guest' => $activeIdentityMatch, 'created' => false];
                }

                $existing->restore();
                $existing->update([...$attributes, 'hotel_id' => $hotel->id]);

                return ['guest' => $existing, 'created' => false];
            }
        }

        // Same real person, different channel: (hotel_id, channel, external_id)
        // can't catch this since the pair differs, but the email/phone doesn't.
        // Reuse the existing guest instead of creating a second row for them.
        $matchedByIdentity = $this->identity->findExistingGuest(
            $hotel->id,
            $attributes['email'] ?? null,
            $attributes['phone_number'] ?? null,
        );

        if ($matchedByIdentity) {
            if ($matchedByIdentity->trashed()) {
                $matchedByIdentity->restore();
            }

            return ['guest' => $matchedByIdentity, 'created' => false];
        }

        return ['guest' => Guest::create([...$attributes, 'hotel_id' => $hotel->id]), 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $attributes  validated, without hotel_id
     */
    public function update(Guest $guest, array $attributes): Guest
    {
        $guest->update($attributes);

        return $guest;
    }
}
