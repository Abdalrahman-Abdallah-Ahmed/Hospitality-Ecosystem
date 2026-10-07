<?php

namespace App\Ai\Tools;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The guest's own activity bookings at this hotel (SPEC-007 FR-020): upcoming
 * ones soonest first, then the most recent past ones. Prices are shown — the
 * guest sees them when browsing activities anyway (clarification Q7).
 */
class GetOwnBookingsTool implements Tool
{
    private const PAST_LIMIT = 10;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly Guest $guest,
    ) {}

    public function description(): Stringable|string
    {
        return "List the guest's own activity bookings at this hotel — upcoming first, then recent past ones — with activity, date, time, party size, status, reference, price and currency, and whether a cancellation request is waiting for staff. Never returns another guest's bookings.";
    }

    public function handle(Request $request): Stringable|string
    {
        $today = now($this->hotel->timezone)->toDateString();

        $upcoming = $this->bookings()
            ->where(fn (Builder $query) => $query->whereNull('scheduled_date')->orWhere('scheduled_date', '>=', $today))
            ->orderByRaw('scheduled_date asc nulls last')
            ->orderBy('scheduled_time')
            ->get();

        $past = $this->bookings()
            ->where('scheduled_date', '<', $today)
            ->orderByDesc('scheduled_date')
            ->limit(self::PAST_LIMIT)
            ->get();

        if ($upcoming->isEmpty() && $past->isEmpty()) {
            return "The guest has no activity bookings at {$this->hotel->name}.";
        }

        return json_encode([
            'upcoming' => $upcoming->map(fn (Booking $booking) => $this->present($booking))->all(),
            'past' => $past->map(fn (Booking $booking) => $this->present($booking))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Only this guest at this hotel: the Concierge runs without tenant context.
     */
    private function bookings(): Builder
    {
        return Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('guest_id', $this->guest->id)
            ->withExists('openCancellationRequest as cancellation_requested');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'activity' => $booking->item_name,
            'date' => $booking->scheduled_date?->toDateString(),
            'time' => $booking->scheduled_time ? substr((string) $booking->scheduled_time, 0, 5) : null,
            'party_size' => $booking->pax,
            'status' => $booking->status->value,
            'price' => $booking->expected_value,
            'currency' => $booking->currency ?? $this->hotel->currency,
            'cancellation_requested' => (bool) $booking->cancellation_requested,
        ];
    }
}
