<?php

namespace App\Ai\Tools;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\BookingCancellationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Passes a guest's wish to cancel a booking to staff (SPEC-043).
 *
 * The Concierge can never cancel a booking itself (constitution: Activities
 * and Bookings); this only creates the request staff answer from their queue.
 * The booking's status is untouched, whatever the guest says.
 */
class RequestBookingCancellationTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly Guest $guest,
        private readonly ?Reservation $reservation = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Ask hotel staff to cancel one of the guest\'s own activity bookings, identified by its reference (or id). This does not cancel it: staff decide and confirm. Tell the guest their request has been passed on, never that the booking is cancelled.';
    }

    public function handle(Request $request): Stringable|string
    {
        $booking = $this->resolveBooking($request);

        if (! $booking) {
            return 'I could not find a booking with that reference for this guest. Ask the guest for the reference they were given.';
        }

        try {
            ['created' => $created] = app(BookingCancellationService::class)->request(
                $booking,
                $this->guest,
                $request->string('reason')->toString() ?: null,
            );
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return $created
            ? "Your cancellation request for {$booking->reference} has been passed to the team; they will confirm. The booking stays in place until they do."
            : "A cancellation request for {$booking->reference} is already with the team; they will confirm.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_reference' => $schema->string()
                ->description('The booking reference the guest was given, e.g. "DCB-4K2P".'),
            'booking_id' => $schema->string()
                ->description('The booking id, if you have it instead of the reference.'),
            'reason' => $schema->string()
                ->description('Why the guest wants to cancel, in their words, if they said.'),
        ];
    }

    /**
     * Only this guest's bookings at this hotel: another guest's reference
     * finds nothing, the same as a wrong one.
     */
    private function resolveBooking(Request $request): ?Booking
    {
        $query = Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('guest_id', $this->guest->id);

        if ($request->filled('booking_reference')) {
            return $query->where('reference', strtoupper(trim($request->string('booking_reference')->toString())))->first();
        }

        if ($request->filled('booking_id')) {
            $id = $request->string('booking_id')->toString();

            // A reference put in the wrong field is "not found", not a crash.
            return Str::isUuid($id) ? $query->whereKey($id)->first() : null;
        }

        return null;
    }
}
