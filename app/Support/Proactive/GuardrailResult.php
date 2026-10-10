<?php

namespace App\Support\Proactive;

use App\Enums\ProactiveSkipReason;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Support\Pitching\CandidateList;
use App\Support\Pitching\GateReport;
use Carbon\CarbonInterface;

/**
 * What the guardrails decided for one proactive message, right now: send it,
 * wait until later, or drop it — and, for a pitch, what it offers and the
 * gates that allowed it.
 */
final readonly class GuardrailResult
{
    private function __construct(
        public string $action,
        public ?ProactiveSkipReason $reason = null,
        public ?CarbonInterface $until = null,
        public ?Recommendation $recommendation = null,
        public ?GateReport $gates = null,
        public ?CandidateList $candidates = null,
        public bool $isRetry = false,
        public ?Hotel $hotel = null,
        public ?Booking $booking = null,
    ) {}

    public static function send(?Recommendation $recommendation = null, ?GateReport $gates = null, ?CandidateList $candidates = null, bool $isRetry = false, ?Booking $booking = null): self
    {
        return new self('send', recommendation: $recommendation, gates: $gates, candidates: $candidates, isRetry: $isRetry, booking: $booking);
    }

    /**
     * The same decision, carrying the hotel the guardrails already loaded, so
     * the sender does not read it again.
     */
    public function withHotel(Hotel $hotel): self
    {
        return new self($this->action, $this->reason, $this->until, $this->recommendation, $this->gates, $this->candidates, $this->isRetry, $hotel, $this->booking);
    }

    public static function defer(ProactiveSkipReason $reason, CarbonInterface $until): self
    {
        // Often computed in the hotel's timezone; stored timestamps are in
        // the app's, and Eloquent writes the wall time it is given.
        return new self('defer', $reason, $until->copy()->setTimezone(config('app.timezone')));
    }

    public static function skip(ProactiveSkipReason $reason): self
    {
        return new self('skip', $reason);
    }

    public function sends(): bool
    {
        return $this->action === 'send';
    }

    public function defers(): bool
    {
        return $this->action === 'defer';
    }
}
