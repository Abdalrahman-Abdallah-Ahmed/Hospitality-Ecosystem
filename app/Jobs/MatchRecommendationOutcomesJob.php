<?php

namespace App\Jobs;

use App\Enums\AttributionMethod;
use App\Enums\OutcomeType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Services\RecommendationOutcomeService;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * The inference fallback — not the plan.
 *
 * It runs only where nothing observed the link: no booking carrying the
 * recommendation id, no conversational outcome, no staff entry. It can reach
 * two of the six outcomes — BOOKED (a matching booking turned up) and EXPIRED
 * (by elimination). It is structurally incapable of seeing a refusal or an
 * undelivered offer, which is why capture exists.
 *
 * A match is not a cause. A booking following a recommendation is a sequence,
 * not proof: the guest may have booked anyway, because a friend mentioned it
 * or they saw the poster. That is why every row it writes is L2, never L1.
 */
class MatchRecommendationOutcomesJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $matched = 0;

    public int $expired = 0;

    public function __construct(
        public ?string $hotelId = null,
    ) {}

    public function handle(): void
    {
        $hotels = $this->hotelId
            ? Hotel::whereKey($this->hotelId)->get()
            : Hotel::query()->get();

        foreach ($hotels as $hotel) {
            // A queue worker has no HTTP request, so tenancy is set explicitly.
            TenantContext::runForHotel($hotel->id, fn () => $this->matchForHotel($hotel));
        }
    }

    private function matchForHotel(Hotel $hotel): void
    {
        $windowHours = (int) config('recommendations.attribution.window_hours');
        $context = [
            'attribution_window_hours' => $windowHours,
            'matcher_version' => config('recommendations.attribution.matcher_version'),
        ];

        $before = ['matched' => $this->matched, 'expired' => $this->expired];

        // One summary event rather than one per match — a nightly run over
        // thousands of rows must not flood the audit trail.
        EventLogger::withoutRecording(function () use ($hotel, $windowHours, $context) {
            $this->matchBookings($hotel, $windowHours, $context);
            $this->expireUndecided($hotel, $context);
        });

        EventLogger::record($hotel, 'recommendation_outcomes_matched', changes: [
            'matched' => $this->matched - $before['matched'],
            'expired' => $this->expired - $before['expired'],
            ...$context,
        ]);
    }

    /**
     * Same guest or stay, same activity, booking made after the
     * recommendation and inside the window, neither side already matched.
     */
    private function matchBookings(Hotel $hotel, int $windowHours, array $context): void
    {
        $service = app(RecommendationOutcomeService::class);

        $candidates = Booking::where('hotel_id', $hotel->id)
            // A booking carrying the id was captured directly. Never inferred
            // over — direct always wins.
            ->whereNull('recommendation_id')
            ->whereNotNull('activity_id')
            ->whereDoesntHave('outcomeLink')
            ->orderBy('created_at')
            ->cursor();

        foreach ($candidates as $booking) {
            $recommendation = $this->findRecommendationFor($booking, $hotel, $windowHours);

            if (! $recommendation) {
                continue;
            }

            $service->record(
                $recommendation,
                OutcomeType::BOOKED,
                AttributionMethod::INFERRED,
                $booking,
                ['context' => $context, 'occurred_at' => $booking->created_at],
            );

            $this->matched++;
        }
    }

    private function findRecommendationFor(Booking $booking, Hotel $hotel, int $windowHours): ?Recommendation
    {
        $windowOpensAt = $booking->created_at->copy()->subHours($windowHours);

        return Recommendation::where('hotel_id', $hotel->id)
            ->where('activity_id', $booking->activity_id)
            ->where('recommended_at', '<', $booking->created_at)
            ->where('recommended_at', '>=', $windowOpensAt)
            ->whereHas('reservation', function ($query) use ($booking) {
                $query->where(function ($inner) use ($booking) {
                    $inner->where('guest_id', $booking->guest_id);

                    if ($booking->stay_id) {
                        $inner->orWhereHas('stay', fn ($stay) => $stay->whereKey($booking->stay_id));
                    }
                });
            })
            // Anything already carrying a real outcome is spoken for; only a
            // bare recommendation, or one previously written off as expired
            // (attribution NONE), can still be matched.
            ->whereDoesntHave('outcome', fn ($query) => $query
                ->where('attribution_method', '!=', AttributionMethod::NONE->value))
            // The closest preceding recommendation is the most plausible one.
            ->orderByDesc('recommended_at')
            ->first();
    }

    /**
     * The guest departed and nothing was ever recorded. That is EXPIRED
     * reached purely by elimination, so its attribution is NONE and its
     * evidence level L4 — nothing was observed at all.
     */
    private function expireUndecided(Hotel $hotel, array $context): void
    {
        $service = app(RecommendationOutcomeService::class);

        $recommendations = Recommendation::where('hotel_id', $hotel->id)
            ->whereDoesntHave('outcome')
            ->whereHas('reservation.stay', fn ($query) => $query
                ->whereIn('status', [StayStatus::DEPARTED, StayStatus::NO_SHOW]))
            ->cursor();

        foreach ($recommendations as $recommendation) {
            $service->record(
                $recommendation,
                OutcomeType::EXPIRED,
                AttributionMethod::NONE,
                attributes: ['context' => $context],
            );

            $this->expired++;
        }
    }
}
