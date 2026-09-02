<?php

namespace App\Support\Bookings;

use App\Models\Booking;
use RuntimeException;

/**
 * The short code that identifies a booking outside this system.
 *
 * Outlets (restaurant, dive centre, spa) run their own tills, which have never
 * heard of our UUIDs. A guest quotes this code at the desk, the seller types
 * it onto the sale, and it comes back in the day's sales file — which is the
 * only way a payment can ever be matched to the booking that produced it.
 *
 * Because it is read aloud across a noisy lobby, the alphabet drops every
 * character that sounds or looks like another: no 0/O, no 1/I/L, no 5/S.
 * Shape is XXX-XXXX — eight characters, matching the column width on both
 * `bookings.reference` and `transactions.booking_reference`.
 */
class BookingReference
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRTUVWXYZ';

    /**
     * Generate a code that is not already in use. Collisions are vanishingly
     * unlikely (30^7 ≈ 22 billion) but the unique index is the real guard —
     * this just avoids handing it a duplicate.
     */
    public static function generate(): string
    {
        foreach (range(1, 10) as $ignored) {
            $reference = static::random();

            $taken = Booking::withoutGlobalScope('hotel')
                ->withTrashed()
                ->where('reference', $reference)
                ->exists();

            if (! $taken) {
                return $reference;
            }
        }

        throw new RuntimeException('Unable to generate a unique booking reference.');
    }

    private static function random(): string
    {
        $pick = fn (int $length) => collect(range(1, $length))
            ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
            ->implode('');

        return $pick(3).'-'.$pick(4);
    }
}
