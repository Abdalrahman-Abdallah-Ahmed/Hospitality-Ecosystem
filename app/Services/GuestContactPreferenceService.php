<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Str;

/**
 * The only writer of a guest's opt-out from proactive messages and
 * unsolicited offers (SPEC-073 FR-035–FR-037).
 *
 * Opting out never stops answers to the guest's own messages, nor the
 * notices about requests they made (SPEC-007): only what the hotel would
 * send or suggest unasked.
 */
class GuestContactPreferenceService
{
    public const SOURCE_GUEST = 'guest_message';

    public const SOURCE_STAFF = 'staff';

    /**
     * Returns whether this call changed anything; a second opt-out records
     * nothing.
     */
    public function optOut(Guest $guest, string $source): bool
    {
        return $this->set($guest, true, $source);
    }

    public function optIn(Guest $guest, string $source): bool
    {
        return $this->set($guest, false, $source);
    }

    /**
     * `opt_out` or `opt_in` when the whole message is one of the configured
     * keywords, otherwise null. "stop" inside a sentence is not a keyword.
     */
    public function matchKeyword(string $text): ?string
    {
        $normalised = $this->normalise($text);

        if ($normalised === '') {
            return null;
        }

        return match (true) {
            in_array($normalised, array_map(fn ($word) => $this->normalise($word), config('proactive.opt_out_keywords', [])), true) => 'opt_out',
            in_array($normalised, array_map(fn ($word) => $this->normalise($word), config('proactive.opt_in_keywords', [])), true) => 'opt_in',
            default => null,
        };
    }

    private function set(Guest $guest, bool $optedOut, string $source): bool
    {
        // Conditional, so two messages at once record one change.
        $changed = Guest::withoutGlobalScope('hotel')
            ->whereKey($guest->getKey())
            ->when($optedOut, fn ($query) => $query->whereNull('proactive_opted_out_at'), fn ($query) => $query->whereNotNull('proactive_opted_out_at'))
            ->update([
                'proactive_opted_out_at' => $optedOut ? now() : null,
                'proactive_opt_out_source' => $optedOut ? $source : null,
                'updated_at' => now(),
            ]) === 1;

        if ($changed) {
            $guest->refresh();
            EventLogger::record($guest, $optedOut ? 'opted_out' : 'opted_in', changes: [
                'proactive_opted_out' => ['from' => ! $optedOut, 'to' => $optedOut],
                'source' => ['from' => null, 'to' => $source],
            ]);
        }

        return $changed;
    }

    /**
     * Lowercase, punctuation and Arabic tatweel removed, spaces collapsed.
     */
    private function normalise(string $text): string
    {
        $text = str_replace('ـ', '', $text);
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;

        return Str::of($text)->lower()->squish()->toString();
    }
}
