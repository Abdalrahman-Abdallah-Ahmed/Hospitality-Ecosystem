<?php

namespace App\Jobs;

use App\Enums\RecommendationStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes recommendations nobody will ever offer (SPEC-071 FR-010): pending
 * or approved ones that were never offered, for a stay that is over.
 *
 * A stay is over when the reservation was cancelled or checked out, every
 * stay of it departed, never arrived or was cancelled, or its planned
 * departure date has passed in the hotel's timezone. Reservations have no
 * no-show status yet (SPEC-012), so a no-show is read from the stays.
 *
 * Runs per hotel, so "today" is each hotel's own date.
 */
class ExpireRecommendationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        foreach (Hotel::query()->where('is_active', true)->get() as $hotel) {
            TenantContext::runForHotel($hotel->id, fn () => $this->expireFor($hotel));
        }
    }

    private function expireFor(Hotel $hotel): void
    {
        $today = now($hotel->timezone ?: 'UTC')->toDateString();
        $over = [StayStatus::DEPARTED->value, StayStatus::NO_SHOW->value, StayStatus::CANCELLED->value];

        $candidates = Recommendation::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', [RecommendationStatus::PENDING_APPROVAL->value, RecommendationStatus::APPROVED->value])
            ->whereNull('delivered_at')
            ->whereNull('pitch_decision_id')
            ->whereHas('reservation', fn (Builder $reservation) => $reservation->where(fn (Builder $q) => $q
                ->whereIn('status', [ReservationStatus::CANCELLED->value, ReservationStatus::CHECKED_OUT->value])
                ->orWhereDate('departure_date', '<', $today)
                ->orWhere(fn (Builder $q) => $q
                    ->whereHas('stays')
                    ->whereDoesntHave('stays', fn (Builder $stay) => $stay->whereNotIn('status', $over)))))
            ->get();

        foreach ($candidates as $recommendation) {
            $this->expire($recommendation);
        }
    }

    private function expire(Recommendation $recommendation): void
    {
        $from = $recommendation->status;

        // Conditional, so an approval or a pitch that lands meanwhile wins.
        $expired = Recommendation::withoutGlobalScope('hotel')
            ->whereKey($recommendation->getKey())
            ->where('status', $from->value)
            ->whereNull('delivered_at')
            ->whereNull('pitch_decision_id')
            ->update(['status' => RecommendationStatus::EXPIRED->value, 'updated_at' => now()]) === 1;

        if ($expired) {
            EventLogger::record($recommendation->refresh(), 'expired', changes: [
                'status' => ['from' => $from->value, 'to' => RecommendationStatus::EXPIRED->value],
            ]);
        }
    }
}
