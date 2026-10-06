<?php

namespace App\Support\Activities;

use App\Enums\ActivityUnavailableReason;

/**
 * How one activity stands on one hotel-local date. Computed, never stored.
 *
 * `open` says whether the activity runs that day by its own rules (active,
 * in season, not in a closure period, open that weekday). It ignores whether
 * the date is past and whether places are left, so `past` and `remaining`
 * are read alongside it. `reason` says why a date is closed, or that an open
 * date is fully booked.
 *
 * `remaining` is the most people a booking starting that date can still take:
 * for a multi-day activity the tightest of the days it covers. It can be zero
 * or below when capacity was lowered after bookings were taken, and is null
 * when the activity has no capacity.
 */
final readonly class DayAvailability
{
    /**
     * @param  list<array{start: string, end: string}>  $windows
     */
    public function __construct(
        public string $date,
        public bool $open,
        public ?ActivityUnavailableReason $reason,
        public ?string $closureReason,
        public array $windows,
        public ?int $capacity,
        public int $booked,
        public ?int $remaining,
        public bool $past,
    ) {}

    /**
     * Closed by its rules while bookings still hold places: staff need to
     * contact those guests (FR-012).
     */
    public function hasBookingsWhileClosed(): bool
    {
        return ! $this->open && $this->booked > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'open' => $this->open,
            'reason' => $this->reason?->value,
            'closure_reason' => $this->closureReason,
            'windows' => $this->windows,
            'capacity' => $this->capacity,
            'booked' => $this->booked,
            'remaining' => $this->remaining,
            'past' => $this->past,
            'has_bookings_while_closed' => $this->hasBookingsWhileClosed(),
        ];
    }
}
