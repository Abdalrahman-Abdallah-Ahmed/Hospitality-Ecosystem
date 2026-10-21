<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

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
     * A normalised, hashed fingerprint for a guest record. Email takes
     * priority because it stays stable across channels; phone-first matching
     * compares normalized digits directly. Returns null when
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
     * An existing guest at this hotel with the exact identity-hash match.
     * Soft-deleted profiles remain eligible for restoration by registration flows.
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

    /**
     * Resolves reservation party members by phone first, then by a unique
     * live same-hotel email match. Phone matches may be restored.
     *
     * @throws ValidationException when the supplied contacts conflict or the
     *                             email identifies more than one live profile
     */
    public function findForReservation(string $hotelId, ?string $email, ?string $phoneNumber): ?Guest
    {
        $phone = PhoneNumber::digits($phoneNumber);
        $phoneMatch = $phone ? Guest::withTrashed()
            ->where('hotel_id', $hotelId)
            ->whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$phone])
            ->first() : null;

        $normalizedEmail = static::normalizeEmail($email);
        $emailMatches = $normalizedEmail ? Guest::query()
            ->where('hotel_id', $hotelId)
            ->where('identity_hash', 'email:'.hash('sha256', $normalizedEmail))
            ->get() : collect();

        if ($phoneMatch && $emailMatches->isNotEmpty() && ! $emailMatches->contains('id', $phoneMatch->id)) {
            throw ValidationException::withMessages(['guests' => 'The supplied phone number and email identify different guests.']);
        }

        if ($phoneMatch) {
            return $phoneMatch;
        }

        if ($emailMatches->count() > 1) {
            throw ValidationException::withMessages(['guests' => 'The supplied email matches more than one guest at this hotel.']);
        }

        return $emailMatches->first();
    }

    protected static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }
}
