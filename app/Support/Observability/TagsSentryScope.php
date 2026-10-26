<?php

namespace App\Support\Observability;

use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Sentry\Event as SentryEvent;
use Sentry\State\Scope;

/**
 * Tags what reaches Sentry with the hotel and the operation, so an error is
 * actionable without carrying any guest data (spec 017 FR-027, research R13).
 *
 * Requests are tagged by ResolveTenant with the route; jobs by a
 * JobProcessing listener with their class and queue. The hotel comes from
 * TenantContext when the event is captured, so a job's hotel shows when the
 * error is reported inside its runForHotel. TenantContext itself stays free
 * of observability code. Does nothing while Sentry has no DSN.
 */
class TagsSentryScope
{
    public static function register(): void
    {
        if (! self::enabled()) {
            return;
        }

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => self::tag([
            'operation' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
        ]));

        \Sentry\configureScope(fn (Scope $scope) => $scope->addEventProcessor(function (SentryEvent $event): SentryEvent {
            if (! array_key_exists('hotel_id', $event->getTags()) && ($hotelId = TenantContext::currentHotelId())) {
                $event->setTag('hotel_id', $hotelId);
            }

            return $event;
        }));
    }

    /**
     * A null value removes the tag rather than being skipped, so a scope that
     * outlives a request (Octane) never carries the last request's hotel.
     *
     * @param  array<string, ?string>  $tags
     */
    public static function tag(array $tags): void
    {
        if (! self::enabled()) {
            return;
        }

        \Sentry\configureScope(function (Scope $scope) use ($tags) {
            foreach ($tags as $key => $value) {
                filled($value) ? $scope->setTag($key, (string) $value) : $scope->removeTag($key);
            }
        });
    }

    private static function enabled(): bool
    {
        return filled(config('sentry.dsn'));
    }
}
