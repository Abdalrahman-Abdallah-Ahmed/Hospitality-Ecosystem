<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\PhoneNumber;

/**
 * Conservative, exact-match guest identity resolution: the same email, or
 * the same phone number, is treated as strong evidence of the same person.
 * Fuzzy matching (names, partial phones, ...) is deliberately out of scope
 * — a false-positive match silently fuses two strangers' histories, which
 * is worse than missing a real match.
 */
class GuestIdentityService
{
    /**
     * A normalised, hashed fingerprint for exact-match lookups. Email takes
     * priority over phone when both are present, since email is less prone
     * to formatting drift across channels/imports. Returns null when
     * there's nothing usable to fingerprint.
     */
    public function computeIdentityHash(Guest $guest): ?string
    {
        if ($email = static::normalizeEmail($guest->email)) {
            return 'email:'.hash('sha256', $email);
        }

        if ($phone = PhoneNumber::digits($guest->phone_number)) {
            return 'phone:'.hash('sha256', $phone);
        }

        return null;
    }

    /**
     * An existing guest **at this hotel** whose email or phone matches the
     * given attributes — used to stop the same real person from getting a
     * second row just because they arrived through a different channel
     * (e.g. once direct, once via Booking.com). Includes soft-deleted rows,
     * so the caller can restore rather than duplicate.
     *
     * Deliberately scoped to one hotel, not searched across every hotel on
     * the platform — matching across unrelated hotels would leak one
     * hotel's guest relationship to another hotel's admin just because
     * they share an email, which is its own cross-tenant leak.
     */
    public function findExistingGuest(string $hotelId, ?string $email, ?string $phoneNumber): ?Guest
    {
        $hash = $this->computeIdentityHash(new Guest(['email' => $email, 'phone_number' => $phoneNumber]));

        if (! $hash) {
            return null;
        }

        return Guest::withTrashed()
            ->where('hotel_id', $hotelId)
            ->where('identity_hash', $hash)
            ->first();
    }

    protected static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }
}
