<?php

namespace App\Http\Controllers;

use App\Enums\AttributionMethod;
use App\Enums\BookingStatus;
use App\Enums\EvidenceLevel;
use App\Enums\OutcomeType;
use App\Models\Booking;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    /**
     * Did the recommendations work? GET /api/analytics/conversion.
     *
     * A recommendation succeeds when it produces a **booking**, not a payment.
     * Money may arrive later, in parts, or never — and "never" is often the
     * correct outcome, because an all-inclusive guest booking an included
     * activity pays nothing and the recommendation worked perfectly.
     *
     * Four numbers, each answering a different question. Reporting one without
     * the others is how a metric becomes misleading:
     *
     *   acceptance   — is the offer any good?        (the conversation)
     *   booking      — did acceptance become commitment?  ← the agent's score
     *   realisation  — did the guest actually attend?     (the bookings)
     *   settlement   — did money arrive, where money was due?  (the ledger)
     *
     * And the diagnostic that falls out of them: the fulfilment gap, guests who
     * accepted and never ended up with a booking. That is a desk that was
     * closed or a price that did not match, and nobody at the property can see
     * it today.
     */
    public function conversion(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Recommendation::class);

        [$from, $to] = $this->period($request);

        $recommendationsMade = Recommendation::whereBetween('recommended_at', [$from, $to])->count();

        // Outcomes are keyed to their recommendation's period, not their own,
        // so a booking made in September still counts against an August offer.
        $outcomes = RecommendationOutcome::query()
            ->whereHas('recommendation', fn ($query) => $query->whereBetween('recommended_at', [$from, $to]))
            ->with('booking')
            ->get();

        $counts = $outcomes->countBy(fn (RecommendationOutcome $o) => $o->outcome->value);
        $notDelivered = $counts->get(OutcomeType::NOT_DELIVERED->value, 0);
        $declined = $counts->get(OutcomeType::DECLINED->value, 0);
        $booked = $counts->get(OutcomeType::BOOKED->value, 0);
        $expired = $counts->get(OutcomeType::EXPIRED->value, 0);

        // Cumulative: a guest who booked necessarily accepted first. Keeping
        // it cumulative is what makes the two rates comparable and their
        // difference meaningful.
        $accepted = $outcomes->filter(fn (RecommendationOutcome $o) => $o->outcome->reachedAcceptance())->count();

        // Delivery is assumed unless something explicitly said otherwise —
        // stated in the notes, because it is an assumption, not a measurement.
        $delivered = max(0, $recommendationsMade - $notDelivered);

        $bookings = $outcomes->pluck('booking')->filter();
        $realised = $bookings->where('status', BookingStatus::REALISED)->count();
        $settleable = $bookings->filter(fn (Booking $b) => $b->charge_model->settles());
        $includedBookings = $bookings->count() - $settleable->count();

        $settledValueByCurrency = $this->settledValueByCurrency($settleable);
        $expectedValueByCurrency = $outcomes
            ->where('outcome', OutcomeType::BOOKED)
            ->groupBy(fn (RecommendationOutcome $o) => $o->currency ?? 'unknown')
            ->map(fn (Collection $rows) => round((float) $rows->sum(fn ($row) => (float) $row->expected_value), 2));

        $byMethod = $outcomes
            ->where('outcome', OutcomeType::BOOKED)
            ->countBy(fn (RecommendationOutcome $o) => $o->attribution_method->value);
        $observed = $byMethod->get(AttributionMethod::CONVERSATIONAL->value, 0)
            + $byMethod->get(AttributionMethod::DIRECT->value, 0)
            + $byMethod->get(AttributionMethod::STAFF->value, 0);
        $inferred = $byMethod->get(AttributionMethod::INFERRED->value, 0);
        $attributed = $observed + $inferred;

        $settledBookingIds = $settleable
            ->filter(fn (Booking $b) => $b->transactions()->exists())
            ->count();

        $body = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),

            'recommendations_made' => $recommendationsMade,
            'delivered' => $delivered,
            'not_delivered' => $notDelivered,
            'accepted' => $accepted,
            'declined' => $declined,
            'booked' => $booked,
            'expired' => $expired,

            'acceptance_rate' => $delivered > 0 ? round($accepted / $delivered, 4) : null,
            'booking_rate' => $delivered > 0 ? round($booked / $delivered, 4) : null,
            // Now that staff can mark a booking attended, 0 is a real
            // measurement rather than an artefact of nothing being able to
            // record one. Still null when there is nothing to divide by, and
            // notes flags a 0 that may just mean the desk is not using it yet.
            'realisation_rate' => $booked > 0 ? round($realised / $booked, 4) : null,
            'settlement_rate' => $settleable->count() > 0
                ? round($settledBookingIds / $settleable->count(), 4)
                : null,
            'fulfilment_gap' => max(0, $accepted - $booked),

            // Committed vs arrived. Never merged into one figure.
            'expected_value' => $this->singleCurrencyValue($expectedValueByCurrency),
            'settled_value' => $this->singleCurrencyValue($settledValueByCurrency),
            'currency' => $expectedValueByCurrency->count() === 1 ? $expectedValueByCurrency->keys()->first() : null,
            'expected_value_by_currency' => $expectedValueByCurrency->count() > 1 ? $expectedValueByCurrency : null,
            'settled_value_by_currency' => $settledValueByCurrency->count() > 1 ? $settledValueByCurrency : null,
            'included_bookings' => $includedBookings,

            'attribution' => [
                'conversational' => $byMethod->get(AttributionMethod::CONVERSATIONAL->value, 0),
                'direct' => $byMethod->get(AttributionMethod::DIRECT->value, 0),
                'staff' => $byMethod->get(AttributionMethod::STAFF->value, 0),
                'inferred' => $inferred,
                'direct_share' => $attributed > 0 ? round($observed / $attributed, 4) : null,
            ],

            'evidence_level' => $this->weakestEvidenceLevel($outcomes),
            'notes' => $this->notes($outcomes, $observed, $attributed, $accepted, $booked, $includedBookings, $realised, $expectedValueByCurrency->count()),
        ];

        return apiResponse('Conversion analytics fetched successfully.', 200, $body);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : Carbon::now()->endOfDay();

        return [$from, $to];
    }

    /**
     * Money that actually arrived, per currency. Never summed across them —
     * 60 USD plus 40 EUR is not 100 of anything.
     */
    private function settledValueByCurrency(Collection $settleable): Collection
    {
        if ($settleable->isEmpty()) {
            return collect();
        }

        return Transaction::whereIn('booking_id', $settleable->pluck('id'))
            ->get()
            ->groupBy('currency')
            ->map(fn (Collection $rows) => round((float) $rows->sum(fn ($row) => (float) $row->line_total), 2));
    }

    private function singleCurrencyValue(Collection $byCurrency): ?float
    {
        return $byCurrency->count() === 1 ? $byCurrency->first() : null;
    }

    /**
     * The weakest level present, never an average: one inferred row makes the
     * whole figure L2. Averaging would let a pile of guesses hide behind a
     * handful of observations.
     */
    private function weakestEvidenceLevel(Collection $outcomes): ?string
    {
        if ($outcomes->isEmpty()) {
            return null;
        }

        $order = [EvidenceLevel::L1, EvidenceLevel::L2, EvidenceLevel::L3, EvidenceLevel::L4];

        $weakest = $outcomes->reduce(function (?EvidenceLevel $carry, RecommendationOutcome $outcome) use ($order) {
            $level = $outcome->evidence_level;

            if ($carry === null) {
                return $level;
            }

            return array_search($level, $order, true) > array_search($carry, $order, true) ? $level : $carry;
        });

        return $weakest?->value;
    }

    /**
     * Every known gap, stated. A metric shipped without them invites false
     * confidence.
     */
    private function notes(
        Collection $outcomes,
        int $observed,
        int $attributed,
        int $accepted,
        int $booked,
        int $includedBookings,
        int $realised,
        int $currencyCount,
    ): string {
        $window = config('recommendations.attribution.window_hours');
        $notes = [];

        if ($outcomes->isEmpty()) {
            $notes[] = 'No recorded outcomes in this period.';
        } elseif ($attributed === 0) {
            $notes[] = 'No bookings attributed in this period.';
        } else {
            $inferredShare = (int) round((1 - $observed / $attributed) * 100);

            if ($inferredShare > 0) {
                $notes[] = "{$inferredShare}% of attributed bookings are inferred (L2), not observed.";
            }
        }

        if ($includedBookings > 0) {
            $notes[] = "{$includedBookings} bookings are all-inclusive and will never settle; excluded from the settlement rate.";
        }

        $gap = max(0, $accepted - $booked);

        if ($gap > 0) {
            $notes[] = "{$gap} accepted guests produced no booking.";
        }

        if ($booked > 0 && $realised === 0) {
            $notes[] = 'No booking has been marked attended yet — a realisation rate of 0 may mean attendance is not being recorded at the outlets rather than that guests did not turn up.';
        }

        $notes[] = "Attribution window: {$window}h.";
        $notes[] = 'Delivery is assumed unless a recommendation was explicitly recorded as not_delivered.';

        if ($currencyCount > 1) {
            $notes[] = 'Multiple currencies in this period — see the per-currency breakdowns; values are not converted.';
        }

        return implode(' ', $notes);
    }
}
