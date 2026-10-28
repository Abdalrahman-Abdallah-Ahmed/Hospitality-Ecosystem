<?php

use App\Enums\Permission;
use App\Enums\VoucherEmailStatus;
use App\Http\Controllers\ReservationVoucherController;
use App\Jobs\SendReservationVoucherEmailJob;
use App\Mail\ReservationVoucherMail;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\VoucherEmailRequest;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\VoucherMailSender;
use App\Services\Vouchers\VoucherTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Resend\Client as ResendClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake(config('vouchers.disk'));
});

function veEmail($test, Hotel $hotel, Reservation $reservation, array $payload = [], $user = null): TestResponse
{
    return vcPost($test, $user ?? $hotel->owner, "/api/reservation/{$reservation->id}/voucher/email", ['request_key' => (string) Str::uuid(), ...$payload]);
}

function veRun(string $requestId): void
{
    app()->call([new SendReservationVoucherEmailJob($requestId), 'handle']);
}

// --- Requesting (US5) -------------------------------------------------------

it('queues one email on the default lane and answers repeats of the key with the same request', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);

    $first = veEmail($this, $hotel, $reservation, ['request_key' => 'k1'])
        ->assertStatus(202)
        ->assertJsonPath('body.status', 'queued')
        ->assertJsonPath('body.recipient', 'layla@example.com');

    foreach (range(1, 2) as $retry) {
        veEmail($this, $hotel, $reservation, ['request_key' => 'k1'])->assertOk()->assertJsonPath('body.id', $first->json('body.id'));
    }

    Queue::assertPushedOn('default', SendReservationVoucherEmailJob::class);
    Queue::assertPushed(SendReservationVoucherEmailJob::class, 1);
    expect(VoucherEmailRequest::count())->toBe(1);
});

it('treats the same key on another reservation as a new request', function () {
    Queue::fake();
    $hotel = avHotel();

    veEmail($this, $hotel, vcReservation($hotel), ['request_key' => 'k1'])->assertStatus(202);
    veEmail($this, $hotel, vcReservation($hotel), ['request_key' => 'k1'])->assertStatus(202);

    expect(VoucherEmailRequest::count())->toBe(2);
});

it('sends to another address for this email only', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);

    veEmail($this, $hotel, $reservation, ['email' => 'agent@travel.test'])->assertStatus(202)->assertJsonPath('body.recipient', 'agent@travel.test');

    expect(Guest::find($reservation->guest_id)->email)->toBe('layla@example.com');
});

it('needs an address when the guest has none', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel, primary: ['email' => null]);

    veEmail($this, $hotel, $reservation)->assertUnprocessable()->assertJsonValidationErrors('email');
    Queue::assertNothingPushed();
});

it('refuses cancelled and no-show reservations', function (string $status) {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel, ['status' => $status]);

    veEmail($this, $hotel, $reservation)->assertStatus(409);

    expect(VoucherEmailRequest::count())->toBe(0);
})->with(['cancelled', 'no_show']);

it('audits each email request with its recipient and options', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);

    $id = veEmail($this, $hotel, $reservation, ['show_total' => true, 'staff_note' => 'Pickup'])->json('body.id');

    $event = EventLog::where('event_type', 'reservation.voucher_email_requested')->sole();

    expect($event->context)->toMatchArray(['voucher_email_request_id' => $id, 'recipient' => 'layla@example.com', 'language' => 'en', 'show_total' => true])
        ->and($event->changes['staff_note'])->toBe('Pickup');
});

it('caps new voucher emails at five per reservation per day', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);

    foreach (range(1, 5) as $i) {
        veEmail($this, $hotel, $reservation, ['request_key' => "k{$i}"])->assertStatus(202);
    }

    veEmail($this, $hotel, $reservation, ['request_key' => 'k6'])->assertStatus(429);
    veEmail($this, $hotel, $reservation, ['request_key' => 'k1'])->assertOk();
    expect(VoucherEmailRequest::count())->toBe(5);
    Queue::assertPushed(SendReservationVoucherEmailJob::class, 5);

    VoucherEmailRequest::where('request_key', 'k2')->update(['status' => VoucherEmailStatus::FAILED->value]);
    veEmail($this, $hotel, $reservation, ['request_key' => 'k6'])->assertStatus(202);
    veEmail($this, $hotel, $reservation, ['request_key' => 'k7'])->assertStatus(429);

    $this->travel(25)->hours();
    veEmail($this, $hotel, $reservation, ['request_key' => 'k8'])->assertStatus(202);
});

it('lists a reservation\'s email requests, newest first', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    veEmail($this, $hotel, $reservation, ['request_key' => 'older']);
    $this->travel(1)->minutes();
    veEmail($this, $hotel, $reservation, ['request_key' => 'newer']);

    $rows = vcGet($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher/emails")->assertOk()->json('body.data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['created_at'] >= $rows[1]['created_at'])->toBeTrue();
});

it('keeps another hotel out and needs the email permission', function () {
    Queue::fake();
    $hotel = avHotel();
    $other = avHotel();
    $reservation = vcReservation($hotel);

    veEmail($this, $other, $reservation)->assertNotFound();
    vcGet($this, $other->owner, "/api/reservation/{$reservation->id}/voucher/emails")->assertNotFound();

    veEmail($this, $hotel, $reservation, user: vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER_EMAIL]))->assertStatus(202);
    veEmail($this, $hotel, $reservation, user: vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER]))->assertForbidden();
    vcGet($this, vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER]), "/api/reservation/{$reservation->id}/voucher/emails")->assertForbidden();
});

// --- Sending (US5) ----------------------------------------------------------

it('sends one voucher email with the PDF attached and records it as sent', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel, primary: ['preferred_language' => 'ar']);
    $id = veEmail($this, $hotel, $reservation)->json('body.id');

    veRun($id);
    veRun($id);

    Mail::assertSent(ReservationVoucherMail::class, 1);
    Mail::assertSent(ReservationVoucherMail::class, function (ReservationVoucherMail $mail) use ($reservation) {
        $attachments = $mail->attachments();

        return $mail->hasTo('layla@example.com')
            && $mail->envelope()->subject === __('voucher.mail.subject', ['reference' => $reservation->reservation_id], 'ar')
            && count($attachments) === 1
            && str_starts_with($mail->pdf, '%PDF')
            && $mail->filename() === "voucher-{$reservation->reservation_id}.pdf";
    });

    $request = VoucherEmailRequest::find($id);

    expect($request->status)->toBe(VoucherEmailStatus::SENT)
        ->and($request->sent_at)->not->toBeNull()
        ->and(EventLog::where('event_type', 'voucher_email_request.sent')->where('subject_id', $id)->exists())->toBeTrue();
});

it('skips the send when the reservation was cancelled before it went out', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    $id = veEmail($this, $hotel, $reservation)->json('body.id');

    $reservation->update(['status' => 'cancelled']);
    veRun($id);

    Mail::assertNothingSent();
    expect(VoucherEmailRequest::find($id))->status->toBe(VoucherEmailStatus::SKIPPED)
        ->status_reason->toBe('reservation_cancelled');
});

it('leaves a fresh claim alone and takes over a stale one', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    VoucherEmailRequest::whereKey($id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(2)]);
    $job = (new SendReservationVoucherEmailJob($id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    Mail::assertNothingSent();
    // Comes back once the claim would be stale, rather than ending as a success.
    $job->assertReleased(delay: 360);

    VoucherEmailRequest::whereKey($id)->update(['claimed_at' => now()->subMinutes(6)]);
    veRun($id);
    Mail::assertSent(ReservationVoucherMail::class, 1);

    // A finished request is simply left alone.
    $done = (new SendReservationVoucherEmailJob($id))->withFakeQueueInteractions();
    app()->call([$done, 'handle']);
    $done->assertNotReleased();
});

it('hands the claim back whatever fails after it, even recording the send', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    // The mail goes out, then recording it fails.
    VoucherEmailRequest::saving(function (VoucherEmailRequest $request) {
        if ($request->status === VoucherEmailStatus::SENT) {
            throw new RuntimeException('database went away');
        }
    });

    expect(fn () => veRun($id))->toThrow(RuntimeException::class, 'database went away');
    expect(VoucherEmailRequest::find($id))->status->toBe(VoucherEmailStatus::QUEUED)
        ->claimed_at->toBeNull();

    VoucherEmailRequest::flushEventListeners();
});

it('skips the send when the reservation was deleted after the request', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    $id = veEmail($this, $hotel, $reservation)->json('body.id');

    $reservation->delete();
    veRun($id);

    Mail::assertNothingSent();
    expect(VoucherEmailRequest::find($id))->status->toBe(VoucherEmailStatus::SKIPPED)
        ->status_reason->toBe('reservation_deleted');
});

it('emails the primary guest named on the voucher, not the booker', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    $booker = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Travel', 'last_name' => 'Agent', 'email' => 'booker@agency.test', 'preferred_language' => 'ar', 'external_id' => 'ext-'.Str::random(8), 'channel' => 'booking_com']);
    $reservation->update(['guest_id' => $booker->id]);

    veEmail($this, $hotel, $reservation->fresh())
        ->assertStatus(202)
        ->assertJsonPath('body.recipient', 'layla@example.com')
        ->assertJsonPath('body.language', 'en');
});

it('quotes a sender name with a comma in the From header', function () {
    Queue::fake();
    config(['mail.default' => 'resend', 'mail.from.address' => 'stay@hotel.test', 'mail.from.name' => 'Grand Hotel, Dubai']);
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    $from = null;
    $emails = Mockery::mock();
    $emails->shouldReceive('send')->andReturnUsing(function (array $payload) use (&$from) {
        $from = $payload['from'];

        return (object) ['id' => 'msg_1'];
    });
    $client = Mockery::mock(ResendClient::class);
    $client->shouldReceive('emails')->andReturn($emails);
    app()->instance(ResendClient::class, $client);

    veRun($id);

    expect($from)->toBe('"Grand Hotel, Dubai" <stay@hotel.test>');
});

it('sorts and bounds the email history like other lists', function () {
    Queue::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    veEmail($this, $hotel, $reservation, ['request_key' => 'a', 'email' => 'b@test.test']);
    veEmail($this, $hotel, $reservation, ['request_key' => 'b', 'email' => 'a@test.test']);

    expect(vcGet($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher/emails?sort=recipient")->assertOk()->json('body.data.0.recipient'))->toBe('a@test.test');
    vcGet($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher/emails?per_page=1000")->assertUnprocessable();
});

it('marks the request failed when the last try fails', function () {
    Queue::fake();
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    $sender = Mockery::mock(VoucherMailSender::class);
    $sender->shouldReceive('isIdempotent')->andReturn(true);
    $sender->shouldReceive('send')->andThrow(new RuntimeException('mailer down'));
    app()->instance(VoucherMailSender::class, $sender);

    expect(fn () => veRun($id))->toThrow(RuntimeException::class);
    expect(VoucherEmailRequest::find($id)->status)->toBe(VoucherEmailStatus::QUEUED);

    (new SendReservationVoucherEmailJob($id))->failed(new RuntimeException('mailer down'));

    expect(VoucherEmailRequest::find($id))->status->toBe(VoucherEmailStatus::FAILED)
        ->status_reason->toBe('RuntimeException');
});

it('renders with the options chosen at request time', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    $id = veEmail($this, $hotel, $reservation)->json('body.id');

    Guest::find($reservation->guest_id)->update(['preferred_language' => 'ar']);
    veRun($id);

    Mail::assertSent(ReservationVoucherMail::class, fn (ReservationVoucherMail $mail) => $mail->language->value === 'en');
});

it('records fields the template has no place for', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => 'Main', 'language' => 'en', 'is_default' => true], vcImage(1240, 1754), null);
    app(VoucherTemplateService::class)->saveLayout($template, [['id' => 'g', 'field' => 'guest.primary_name', 'x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.05, 'font_size' => 12, 'color' => '#000000', 'bold' => false, 'align' => 'start', 'overflow' => 'shrink', 'text' => null]]);
    $id = veEmail($this, $hotel, vcReservation($hotel), ['staff_note' => 'Pickup'])->json('body.id');

    veRun($id);

    expect(VoucherEmailRequest::find($id)->unplaced)->toBe(['voucher.staff_note'])
        ->and(VoucherEmailRequest::find($id)->voucher_template_id)->toBe($template->id)
        ->and(VoucherTemplate::count())->toBe(1);
});

it('sends through Resend with a fixed idempotency key, reused after a crash', function () {
    Queue::fake();
    config(['mail.default' => 'resend', 'mail.from.address' => 'stay@hotel.test', 'mail.from.name' => 'Hotel']);
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    $calls = [];
    $emails = Mockery::mock();
    $emails->shouldReceive('send')->andReturnUsing(function (array $payload, array $options) use (&$calls) {
        $calls[] = [$payload, $options];

        return (object) ['id' => 'msg_1'];
    });
    $client = Mockery::mock(ResendClient::class);
    $client->shouldReceive('emails')->andReturn($emails);
    app()->instance(ResendClient::class, $client);

    veRun($id);

    // A worker killed after the send: the row is left claimed, and the
    // takeover sends again with the same key, which Resend drops.
    VoucherEmailRequest::whereKey($id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(6)]);
    veRun($id);

    expect($calls)->toHaveCount(2)
        ->and($calls[0][1])->toBe(['idempotency_key' => "voucher-email:{$id}"])
        ->and($calls[1][1])->toBe(['idempotency_key' => "voucher-email:{$id}"])
        ->and($calls[0][0]['attachments'])->toHaveCount(1)
        ->and($calls[0][0]['attachments'][0]['content_type'])->toBe('application/pdf')
        ->and(base64_decode($calls[0][0]['attachments'][0]['content']))->toStartWith('%PDF')
        ->and($calls[0][0]['to'])->toBe(['layla@example.com']);
});

// --- Only staff can send (FR-031) -------------------------------------------

arch('only the staff endpoint dispatches the voucher email job')
    ->expect(SendReservationVoucherEmailJob::class)
    ->toOnlyBeUsedIn([ReservationVoucherController::class]);

arch('the voucher mail is only built by the job and sent by the sender')
    ->expect(ReservationVoucherMail::class)
    ->toOnlyBeUsedIn([SendReservationVoucherEmailJob::class, VoucherMailSender::class]);

// --- Review fixes -----------------------------------------------------------

it('refuses to change the hotel details on an emailed voucher', function (string $key) {
    Queue::fake();
    $hotel = avHotel();

    veEmail($this, $hotel, vcReservation($hotel), ['overrides' => [$key => 'Attacker Contact']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("overrides.{$key}");

    expect(VoucherEmailRequest::count())->toBe(0);
})->with(['hotel_name', 'hotel_address', 'hotel_phone', 'hotel_email']);

it('caps each staff user at twenty voucher emails an hour across reservations', function () {
    Queue::fake();
    $hotel = avHotel();
    $user = vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER_EMAIL]);

    foreach (range(1, 4) as $r) {
        $reservation = vcReservation($hotel);
        foreach (range(1, 5) as $i) {
            veEmail($this, $hotel, $reservation, user: $user)->assertStatus(202);
        }
    }

    veEmail($this, $hotel, vcReservation($hotel), user: $user)->assertStatus(429);
    veEmail($this, $hotel, vcReservation($hotel))->assertStatus(202); // another user is not affected

    $this->travel(61)->minutes();
    veEmail($this, $hotel, vcReservation($hotel), user: $user)->assertStatus(202);
});

it('sends at most once with a mailer that cannot drop duplicates', function () {
    Queue::fake();
    Mail::fake();
    $hotel = avHotel();
    $id = veEmail($this, $hotel, vcReservation($hotel))->json('body.id');

    expect(app(VoucherMailSender::class)->isIdempotent())->toBeFalse();

    // An earlier attempt reached the mailer, then failed before recording.
    VoucherEmailRequest::whereKey($id)->update(['send_started_at' => now()->subMinute()]);
    veRun($id);

    Mail::assertNothingSent();
    expect(VoucherEmailRequest::find($id))->status->toBe(VoucherEmailStatus::FAILED)
        ->status_reason->toBe('uncertain_delivery');
});

it('treats any mailer using the Resend transport as idempotent, but not a failover', function () {
    config(['mail.mailers.transactional' => ['transport' => 'resend'], 'mail.default' => 'transactional']);
    expect(app(VoucherMailSender::class)->isIdempotent())->toBeTrue();

    config(['mail.default' => 'failover']);
    expect(app(VoucherMailSender::class)->isIdempotent())->toBeFalse();
});
