<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\InboundMessageStatus;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

/*
| Spec 024: a suspended hotel's WhatsApp messages get one fixed reply a day, with
| no AI run, and STOP still works.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    Sleep::fake();
});

function swJob(User|Guest $sender, ?Hotel $hotel, string $text = 'Hello', bool $paired = true, string $phone = '201000000777'): ProcessInboundWhatsAppMessageJob
{
    return new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => $phone, 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: $phone,
        messageText: $text,
        senderType: $sender instanceof Guest ? SenderType::GUEST : SenderType::ADMIN,
        sender: $sender,
        hotel: $hotel,
        reservation: null,
        devicePaired: $paired,
    );
}

function swRun(ProcessInboundWhatsAppMessageJob $job): void
{
    $job->handle(app(WhatsAppMessageService::class), app(MeteringService::class));
}

function swGuest(Hotel $hotel, string $language = 'en'): Guest
{
    return gsGuest($hotel, ['preferred_language' => $language, 'phone_number' => '201000000777']);
}

it('keeps the English reply equal to the existing unavailable reply', function () {
    expect(__('suspension.guest_reply', [], 'en'))->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
});

it('gives a guest the fixed reply, in their language, with no AI run', function (string $language, string $expected) {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel, $language);
    spSuspend($hotel);

    $job = swJob($guest, $hotel);
    swRun($job);

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toBe($expected)
        ->and($job->inbound->fresh())
        ->status->toBe(InboundMessageStatus::REPLIED)
        ->reply_text->toBe($expected)
        ->generation_started_at->toBeNull();

    GuestConciergeAgent::assertNeverPrompted();
})->with([
    'English' => ['en', ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY],
    'Arabic' => ['ar', 'عذراً، لا يمكنني الرد على الرسائل الآن. يرجى التواصل مع الفندق مباشرة للمساعدة.'],
]);

it('applies when only the account is suspended', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [$account, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($account);

    swRun(swJob($guest, $hotel));

    expect($whatsApp->sent)->toHaveCount(1);
    GuestConciergeAgent::assertNeverPrompted();
});

it('replies once in 24 hours per hotel and phone, keeping later messages', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);

    swRun(swJob($guest, $hotel));

    $repeat = swJob($guest, $hotel, 'Anyone there?');
    swRun($repeat);

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($repeat->inbound->fresh())
        ->status->toBe(InboundMessageStatus::THROTTLED)
        ->reply_text->toBeNull()
        ->replied_at->toBeNull();

    $this->travel(24)->hours();
    $this->travel(1)->minutes();

    swRun(swJob($guest, $hotel, 'Still there?'));

    expect($whatsApp->sent)->toHaveCount(2);
});

it('limits each hotel and phone separately', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $a, $b] = spAccount();
    $guestA = swGuest($a);
    $guestB = swGuest($b);
    spSuspend($a);
    spSuspend($b);

    swRun(swJob($guestA, $a));
    swRun(swJob($guestB, $b));
    swRun(swJob($guestA, $a, phone: '201000000888'));

    expect($whatsApp->sent)->toHaveCount(3);
});

it('repeats a decided reply when its send is retried', function () {
    $whatsApp = gsFakeWhatsApp(failures: 1);
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);

    $job = swJob($guest, $hotel);

    expect(fn () => swRun($job))->toThrow(RuntimeException::class);
    expect($job->inbound->fresh()->reply_text)->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);

    swRun($job);

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($job->inbound->fresh()->status)->toBe(InboundMessageStatus::REPLIED);
});

it('does not use up the daily reply on a send that failed', function () {
    $whatsApp = gsFakeWhatsApp(failures: 1);
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);

    expect(fn () => swRun(swJob($guest, $hotel)))->toThrow(RuntimeException::class);

    swRun(swJob($guest, $hotel, 'Hello?'));

    expect($whatsApp->sent)->toHaveCount(1);

    $repeat = swJob($guest, $hotel, 'Hello again?');
    swRun($repeat);

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($repeat->inbound->fresh()->status)->toBe(InboundMessageStatus::THROTTLED);
});

it('leaves a turn that already started on its own path, without using up the daily reply', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);

    $started = swJob($guest, $hotel);
    $started->inbound->update(['generation_started_at' => now()]);
    swRun($started);

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::FAILED_REPLY);
    GuestConciergeAgent::assertNeverPrompted();

    swRun(swJob($guest, $hotel, 'Hello?'));

    expect($whatsApp->sent[1]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
});

it('still honors STOP, and does not use up the daily reply', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);

    swRun(swJob($guest, $hotel, 'STOP'));

    expect($guest->fresh()->proactive_opted_out_at)->not->toBeNull()
        ->and($whatsApp->sent[0]['text'])->toBe(__('proactive.opt_out.confirmed', [], 'en'));

    swRun(swJob($guest, $hotel));

    expect($whatsApp->sent)->toHaveCount(2)
        ->and($whatsApp->sent[1]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
});

it('gives an unpaired admin the not-paired reply, which says nothing about suspension', function () {
    $whatsApp = gsFakeWhatsApp();
    AdminAdvisorAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $admin = spUser($hotel);
    spSuspend($hotel);

    swRun(swJob($admin, $hotel, paired: false));

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::NOT_PAIRED_REPLY);
    AdminAdvisorAgent::assertNeverPrompted();
});

it('gives a paired admin the fixed reply in English, even with other active hotels', function () {
    $whatsApp = gsFakeWhatsApp();
    AdminAdvisorAgent::fake()->preventStrayPrompts();
    [, $a, $b] = spAccount();
    $admin = spUser($a, [$b]);
    spSuspend($a);

    swRun(swJob($admin, $a));

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
    AdminAdvisorAgent::assertNeverPrompted();
});

it('stops the AI for an admin with a soft-deleted default hotel in a suspended account', function () {
    $whatsApp = gsFakeWhatsApp();
    AdminAdvisorAgent::fake()->preventStrayPrompts();
    [$account, $hotel] = spAccount();
    $admin = spUser($hotel);
    spSuspend($account);
    $hotel->delete();

    swRun(swJob($admin->fresh(), null));

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
    AdminAdvisorAgent::assertNeverPrompted();
});

it('stops the AI for a guest whose suspended hotel was soft-deleted', function () {
    $whatsApp = gsFakeWhatsApp();
    GuestConciergeAgent::fake()->preventStrayPrompts();
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);
    $hotel->delete();

    swRun(swJob($guest, null));

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
    GuestConciergeAgent::assertNeverPrompted();
});

it('stops the AI for an admin with no hotel left in a suspended account', function () {
    $whatsApp = gsFakeWhatsApp();
    AdminAdvisorAgent::fake()->preventStrayPrompts();
    $account = HotelGroup::create(['name' => 'Empty', 'slug' => 'empty']);
    spSuspend($account);
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $account->id]);

    swRun(swJob($admin, null));

    expect($whatsApp->sent[0]['text'])->toBe(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY);
    AdminAdvisorAgent::assertNeverPrompted();
});

it('runs the AI again after reactivation', function () {
    gsFakeWhatsApp();
    GuestConciergeAgent::fake(['Of course!']);
    [, $hotel] = spAccount();
    $guest = swGuest($hotel);
    spSuspend($hotel);
    swRun(swJob($guest, $hotel));

    spReactivate($hotel);
    swRun(swJob($guest, $hotel->fresh(), 'Hello again'));

    GuestConciergeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Hello again'));
});
