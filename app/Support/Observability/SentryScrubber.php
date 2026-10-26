<?php

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Strips personal and secret data from every error and trace before it
 * leaves the server for Sentry (spec 017 FR-027, FR-027a, research R13).
 *
 * Removed: request bodies, cookies and all but a few harmless headers; any
 * extra, context or tag key that names contact details, message text or a
 * secret, at any depth; email addresses and phone numbers inside messages
 * and breadcrumbs; everything about the user but their id. Kept: the
 * exception, its frames, and the hotel, account, operation and queue tags
 * that make an error actionable.
 */
class SentryScrubber
{
    private const SENSITIVE_KEY = '/phone|email|body|message|text|content|token|password|secret|api[_-]?key|authorization|identity|cookie/i';

    private const SAFE_HEADERS = ['accept', 'content-type', 'user-agent'];

    /** Tags we set ourselves to make an error actionable; none is personal. */
    private const KEPT_TAGS = ['hotel_id', 'hotel_group_id', 'operation', 'queue'];

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * A number written with a leading +, separators allowed, or a bare run
     * of 9 to 15 digits. Digits joined to a dash, dot, colon or slash are
     * left alone, so dates, times, amounts and uuid parts stay readable.
     */
    private const PHONE = '~\+\d[\d \-]{7,}\d|(?<![\d.:/\-])\d{9,15}(?![\d.:/\-])~';

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    private static function scrub(Event $event): Event
    {
        $event->setRequest(self::request($event->getRequest()));
        $event->setExtra(self::keys($event->getExtra()));

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext((string) $name, self::keys((array) $data));
        }

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            if (in_array($key, self::KEPT_TAGS, true) || ! preg_match(self::SENSITIVE_KEY, (string) $key)) {
                $tags[$key] = self::text((string) $value);
            }
        }
        $event->setTags($tags);

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage(self::text($message));
        }

        $event->setBreadcrumb(array_map(self::breadcrumb(...), $event->getBreadcrumbs()));

        $user = $event->getUser();
        $event->setUser($user?->getId() !== null ? UserDataBag::createFromUserIdentifier($user->getId()) : null);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function request(array $request): array
    {
        unset($request['data'], $request['cookies'], $request['query_string'], $request['env']);

        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = array_filter(
                $request['headers'],
                fn ($name) => in_array(strtolower((string) $name), self::SAFE_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if (isset($request['url'])) {
            $request['url'] = self::text((string) $request['url']);
        }

        return $request;
    }

    /**
     * Drops sensitive keys at any depth and masks what remains.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function keys(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::keys($value),
                is_string($value) => self::text($value),
                is_object($value) => self::keys((array) $value),
                default => $value,
            };
        }

        return $clean;
    }

    private static function breadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        $message = $crumb->getMessage();

        return new Breadcrumb(
            $crumb->getLevel(),
            $crumb->getType(),
            $crumb->getCategory(),
            $message === null ? null : self::text($message),
            self::keys($crumb->getMetadata()),
            $crumb->getTimestamp(),
        );
    }

    private static function text(string $value): string
    {
        return (string) preg_replace([self::EMAIL, self::PHONE], ['[email]', '[phone]'], $value);
    }
}
