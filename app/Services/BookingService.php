<?php

namespace App\Services;

use App\Enums\AttributionMethod;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\MeterFeature;
use App\Enums\OutcomeType;
use App\Models\Booking;
use App\Models\Transaction;
use App\Services\Metering\MeteringService;
use App\Support\Bookings\BookingReference;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only sanctioned way to create or move a booking.
 *
 * The rule this service exists to protect: **booking status and settlement are
 * independent, in both directions.** A booking can be REALISED with no
 * transaction (an all-inclusive guest pays nothing), and a transaction can
 * exist with no booking (a walk-up sale). Neither is ever derived from the
 * other, and no method here goes looking for money to decide a status.
 */
class BookingService
{
    /**
     * Bookings arrive through AI paths — CreateBookingTool in the concierge
     * agent, and the recommendation flow — so this reads like an AI feature
     * and will one day be a tempting thing to put behind a quota or a plan
     * entitlement. It is not one. A booking is an operational commitment: a
     * table held, a guest expected, staff scheduled. Gate the suggestion,
     * never the commitment.
     *
     * The failure that rule prevents: a card expires on Friday, the account
     * suspends, booking creation is gated. Guests keep booking dinner over
     * WhatsApp and the records do not save. Saturday evening the outlet has
     * no covers list and thirty guests arrive expecting tables. Nobody
     * connects it to billing, because nothing in the restaurant's world
     * mentions billing.
     *
     * No enforcement layer exists yet (Phase 2 v2.0 defers WP-9), which is
     * exactly why this is written down here rather than left in a plan.
     */
    public function create(array $data): Booking
    {
        $booking = Booking::create([
            ...$data,
            'reference' => $data['reference'] ?? BookingReference::generate(),
            'status' => $data['status'] ?? BookingStatus::PENDING->value,
        ]);

        $this->creditRecommendation($booking);

        // Metered for reporting only. Nothing bills on it and nothing gates on
        // it — it accumulates so that the eventual "volume or bookings?"
        // pricing decision is made against real data rather than a guess.
        $this->meter($booking, MeterFeature::BOOKINGS_CREATED);

        return $booking;
    }

    /**
     * A booking carrying a recommendation_id *is* the conversion, observed
     * directly — the strongest link in the system, needing no inference at
     * all. Recorded here so it happens whatever created the booking.
     */
    /**
     * Metering is resolved out of the container here rather than injected, so
     * that the many places already constructing a BookingService by hand do
     * not all have to change. It goes through safely(), so a metering failure
     * can never stop a booking being saved — a booking is an operational
     * commitment and outranks the count of it.
     */
    private function meter(Booking $booking, MeterFeature $feature): void
    {
        $metering = app(MeteringService::class);

        $metering->safely(function (MeteringService $m) use ($booking, $feature) {
            $booking->loadMissing('hotel');

            if ($booking->hotel) {
                $m->recordForHotel(
                    hotel: $booking->hotel,
                    feature: $feature,
                    source: $booking,
                    idempotencyKey: $feature->value.':'.$booking->getKey(),
                );
            }
        });
    }

    private function creditRecommendation(Booking $booking): void
    {
        $recommendation = $booking->recommendation()->withoutGlobalScope('hotel')->first();

        if (! $recommendation) {
            return;
        }

        app(RecommendationOutcomeService::class)->record(
            $recommendation,
            OutcomeType::BOOKED,
            AttributionMethod::DIRECT,
            $booking,
            ['channel' => $booking->channel, 'occurred_at' => $booking->created_at],
        );
    }

    /**
     * The slot is held and the guest is expected.
     */
    public function confirm(Booking $booking): Booking
    {
        $this->guardOpen($booking, 'confirmed');

        $booking->update([
            'status' => BookingStatus::CONFIRMED,
            'confirmed_at' => $booking->confirmed_at ?? Carbon::now(),
        ]);

        return $booking;
    }

    /**
     * The guest attended.
     *
     * Deliberately does not look for a transaction. On an INCLUDED booking
     * none will ever exist, and treating its absence as failure would report
     * an ideal outcome as a loss.
     */
    public function realise(Booking $booking, ?CarbonInterface $at = null): Booking
    {
        $this->guardOpen($booking, 'realised');

        $booking->update([
            'status' => BookingStatus::REALISED,
            'realised_at' => $at ?? Carbon::now(),
        ]);

        // Created counts intent; realised counts what actually happened. The
        // gap between them is the number worth watching.
        $this->meter($booking, MeterFeature::BOOKINGS_REALISED);

        return $booking;
    }

    /**
     * Confirmed, and the guest never came. Distinct from a cancellation: one
     * is a broken commitment, the other is one withdrawn in time. Different
     * operational responses, different signals.
     */
    public function markNoShow(Booking $booking): Booking
    {
        $this->guardOpen($booking, 'no_show');

        $booking->update([
            'status' => BookingStatus::NO_SHOW,
            'realised_at' => null,
        ]);

        return $booking;
    }

    /**
     * Withdrawn before the date. A booking is never deleted — the history is
     * the point, same as the ledger.
     */
    public function cancel(Booking $booking, string $reason): Booking
    {
        if ($booking->status === BookingStatus::REALISED) {
            throw new RuntimeException('A realised booking cannot be cancelled; it already happened.');
        }

        $booking->update([
            'status' => BookingStatus::CANCELLED,
            'cancelled_at' => Carbon::now(),
            'cancellation_reason' => $reason,
        ]);

        return $booking;
    }

    /**
     * Attach a payment to the commitment it settles. Direct reference only —
     * there is no booking↔transaction inference in Phase 1, and the booking's
     * own status is untouched by this.
     */
    public function linkSettlement(Booking $booking, Transaction $transaction): Booking
    {
        if ($transaction->hotel_id !== $booking->hotel_id) {
            throw new RuntimeException('The transaction belongs to a different hotel.');
        }

        if ($booking->charge_model === ChargeModel::INCLUDED) {
            throw new RuntimeException('An included booking never settles; it cannot carry a payment.');
        }

        // The ledger is append-only, so this is a direct column write rather
        // than a model update — see Transaction's append-only guard.
        Transaction::withoutGlobalScope('hotel')
            ->whereKey($transaction->id)
            ->update(['booking_id' => $booking->id]);

        return $booking;
    }

    /**
     * A cancelled booking is closed. Reopening it would let a stale process
     * quietly resurrect a commitment the guest withdrew.
     */
    private function guardOpen(Booking $booking, string $target): void
    {
        if ($booking->status === BookingStatus::CANCELLED) {
            throw new RuntimeException("A cancelled booking cannot be marked {$target}.");
        }
    }
}
