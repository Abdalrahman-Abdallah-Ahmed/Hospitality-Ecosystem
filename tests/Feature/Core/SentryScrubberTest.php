<?php

use App\Support\Observability\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

/*
| Spec 017 (FR-027, FR-027a, SC-008, research R13): nothing personal or secret
| survives the scrubber, for errors or for traces, while what makes an error
| actionable (the exception, the hotel, the operation) does.
*/

function plantedEvent(Event $event): Event
{
    $event->setRequest([
        'url' => 'https://api.example.test/api/guest?phone=+201001234567',
        'method' => 'POST',
        'data' => ['phone_number' => '+201001234567', 'message' => 'Hi, my room is 214'],
        'cookies' => ['session' => 'cookie-secret'],
        'headers' => [
            'Authorization' => 'Bearer token-secret',
            'X-API-KEY' => 'api-key-secret',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Front desk',
        ],
    ]);
    $event->setExtra(['guest' => ['email' => 'guest@example.test', 'message_text' => 'Please bring towels'], 'attempt' => 2]);
    $event->setContext('job', ['payload' => ['body' => 'Hi from WhatsApp'], 'class' => 'SendWhatsAppMessageJob']);
    $event->setTags(['hotel_id' => 'hotel-123', 'operation' => 'api.guest.store', 'api_key' => 'api-key-secret']);
    $event->setUser(UserDataBag::createFromArray(['id' => 'user-7', 'email' => 'staff@example.test', 'username' => 'Sara']));
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Sent to +20 100 123 4567', ['token' => 'crumb-secret'])]);

    return $event;
}

$planted = ['+201001234567', '201001234567', 'Hi, my room', 'cookie-secret', 'token-secret', 'api-key-secret',
    'guest@example.test', 'Please bring towels', 'Hi from WhatsApp', 'staff@example.test', 'Sara', '+20 100 123 4567', 'crumb-secret'];

it('lets nothing personal or secret through on an error', function () use ($planted) {
    $event = plantedEvent(Event::createEvent());
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Could not reach guest guest@example.test at +201001234567'))]);

    $scrubbed = SentryScrubber::beforeSend($event);
    $dump = serialize($scrubbed);

    foreach ($planted as $value) {
        expect(str_contains($dump, $value))->toBeFalse("'{$value}' survived the scrubber");
    }

    expect($scrubbed->getTags())->toMatchArray(['hotel_id' => 'hotel-123', 'operation' => 'api.guest.store'])
        ->and($scrubbed->getTags())->not->toHaveKey('api_key')
        ->and($scrubbed->getUser()?->getId())->toBe('user-7')
        ->and($scrubbed->getExceptions()[0]->getValue())->toBe('Could not reach guest [email] at [phone]')
        ->and(array_change_key_case($scrubbed->getRequest()['headers']))->toBe(['content-type' => 'application/json', 'user-agent' => 'Front desk'])
        ->and($scrubbed->getExtra())->toBe(['guest' => [], 'attempt' => 2]);
});

it('lets nothing personal or secret through on a trace', function () use ($planted) {
    $scrubbed = SentryScrubber::beforeSendTransaction(plantedEvent(Event::createTransaction()));
    $dump = serialize($scrubbed);

    foreach ($planted as $value) {
        expect(str_contains($dump, $value))->toBeFalse("'{$value}' survived the scrubber");
    }

    expect($scrubbed->getTags())->toHaveKey('hotel_id');
});

it('is wired into the Sentry configuration with personal data off', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'beforeSendTransaction'])
        ->and(config('sentry.breadcrumbs.queue_info'))->toBeFalse()
        ->and(config('sentry.traces_sample_rate'))->toBe(0.1);
});

it('leaves dates, times and amounts readable', function () {
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('No availability on 2026-10-26 at 2026-10-26 12:00:00 for 1234567890.50'))]);

    expect(SentryScrubber::beforeSend($event)->getExceptions()[0]->getValue())
        ->toBe('No availability on 2026-10-26 at 2026-10-26 12:00:00 for 1234567890.50');
});
