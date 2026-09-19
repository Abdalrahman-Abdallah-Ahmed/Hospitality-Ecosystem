<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the first/last contact timestamps on guests and stays.
 *
 * Plain SQL rather than a model update, for two reasons. Guest and Stay both
 * record events, so a model update would write an event_log row for every
 * WhatsApp message forever. And two messages handled by two workers at once
 * would each read "null" and write their own time; LEAST/GREATEST in one
 * statement makes the order irrelevant.
 */
class GuestContactService
{
    /**
     * Record that the guest messaged us at $at, against the stay the message
     * was resolved to (if any). Idempotent and safe out of order: first_* only
     * ever moves earlier, last_* only ever moves later.
     */
    public function recordInbound(Guest $guest, ?Stay $stay, CarbonInterface $at): void
    {
        $this->recordRange($guest, $stay, $at, $at);
    }

    /**
     * Same as recordInbound() for a span of messages at once — the backfill
     * applies each stay's earliest and latest message in one call.
     */
    public function recordRange(Guest $guest, ?Stay $stay, CarbonInterface $first, CarbonInterface $last): void
    {
        $this->stamp('guests', $guest->getKey(), $first, $last);

        // A stay from another hotel would put one hotel's contact on
        // another hotel's records.
        if ($stay && $stay->hotel_id === $guest->hotel_id) {
            $this->stamp('stays', $stay->getKey(), $first, $last);
        }
    }

    private function stamp(string $table, string $id, CarbonInterface $first, CarbonInterface $last): void
    {
        $first = $this->toColumnValue($first);
        $last = $this->toColumnValue($last);

        DB::update(
            "UPDATE {$table}
                SET first_contacted_at = LEAST(COALESCE(first_contacted_at, ?), ?),
                    last_contacted_at  = GREATEST(COALESCE(last_contacted_at, ?), ?)
              WHERE id = ?",
            [$first, $first, $last, $last, $id],
        );
    }

    /**
     * The columns hold app-timezone wall time, like every other timestamp
     * Eloquent writes; a Meta timestamp arrives in whatever zone it was
     * parsed in.
     */
    private function toColumnValue(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
