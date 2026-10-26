<?php

namespace App\Support\Observability;

use App\Notifications\PlatformAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one platform alert per dedupe key per cooldown, so a noisy source
 * (one job class, one lane, one hotel group) cannot flood the inbox — and,
 * because each source has its own key, cannot drown out the others either
 * (spec 017 FR-028, research R14).
 */
class PlatformAlerts
{
    /**
     * Never throws: an alert rides along on paths (a guest reply, a failing
     * job) whose own handling must not be replaced by a mail error. The
     * dedupe key is kept only when the mail went out, so a skipped or failed
     * send does not silence the source for the whole cooldown.
     *
     * @param  list<string>  $lines
     * @return bool whether the alert was sent
     */
    public static function send(string $dedupeKey, int $cooldownMinutes, string $subject, array $lines): bool
    {
        $address = config('ops.alert_email');

        if (blank($address)) {
            Log::warning('Platform alert not mailed: OPS_ALERT_EMAIL is not set.', ['subject' => $subject]);

            return false;
        }

        if (! Cache::add($dedupeKey, true, now()->addMinutes($cooldownMinutes))) {
            return false;
        }

        try {
            Notification::route('mail', $address)->notify(new PlatformAlertNotification($subject, $lines));
        } catch (Throwable $e) {
            Cache::forget($dedupeKey);
            report($e);

            return false;
        }

        return true;
    }
}
