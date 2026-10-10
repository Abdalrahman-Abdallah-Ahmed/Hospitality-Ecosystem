<?php

namespace App\Services\Proactive;

use App\Enums\ProactiveTrigger;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The text of a proactive message (SPEC-073 FR-032): a fixed wording per
 * trigger and language from lang/{en,ar}/proactive.php, filled only from the
 * hotel's own records. No model writes or rewrites it.
 */
class ProactiveMessageRenderer
{
    public const DESCRIPTION_LIMIT = 160;

    private const PLACEHOLDERS = '/:(guest|hotel|activity|description|date|time)\b/';

    /**
     * @return array{locale: string, body: string}|null null when a required
     *                                                  value is missing
     */
    public function render(ProactiveTrigger $trigger, Guest $guest, Hotel $hotel, ?Recommendation $recommendation, ?Booking $booking): ?array
    {
        $locale = $guest->preferred_language === 'ar' ? 'ar' : 'en';
        $timezone = $hotel->timezone ?: 'UTC';

        $activity = $trigger === ProactiveTrigger::UPCOMING_ACTIVITY
            ? ($booking?->activity?->name ?? $booking?->item_name)
            : $recommendation?->activity?->name;

        if (blank($activity)) {
            return null;
        }

        $values = [
            'guest' => $guest->first_name ?: ($locale === 'ar' ? 'ضيفنا العزيز' : 'there'),
            'hotel' => $hotel->name,
            'activity' => $activity,
        ];

        if ($trigger === ProactiveTrigger::UPCOMING_ACTIVITY) {
            if (! $booking?->scheduled_for) {
                return null;
            }

            $start = $booking->scheduled_for->copy()->setTimezone($timezone);
            $key = 'upcoming_activity.with_time';
            $values['date'] = $start->locale($locale)->translatedFormat('l j F');
            $values['time'] = $start->format('H:i');
        } else {
            $description = $this->description($recommendation?->activity?->description);
            $key = $trigger->value.'.'.match (true) {
                $description === null => 'without_description',
                $trigger === ProactiveTrigger::RECOMMENDATION_APPROVED => 'default',
                default => 'with_recommendation',
            };

            if ($description !== null) {
                $values['description'] = $description;
            }
        }

        $body = __("proactive.{$key}", $values, $locale);

        // An unfilled placeholder would reach the guest as ":time".
        if (preg_match(self::PLACEHOLDERS, $body)) {
            throw new RuntimeException("Proactive text [{$key}] has an unfilled placeholder.");
        }

        return ['locale' => $locale, 'body' => $body];
    }

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);

        if ($description === '') {
            return null;
        }

        $short = Str::limit($description, self::DESCRIPTION_LIMIT, '…', preserveWords: true);

        return preg_match('/[.!?…]$/u', $short) ? $short : $short.'.';
    }
}
