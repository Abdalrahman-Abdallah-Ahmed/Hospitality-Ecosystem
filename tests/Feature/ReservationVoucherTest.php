<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherRenderer;
use App\Services\Vouchers\VoucherTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake(config('vouchers.disk'));
});

function vrHotel(): Hotel
{
    $hotel = avHotel();
    $hotel->update(['name' => 'Grand Harbor Hotel', 'address' => '12 Corniche Road', 'phone' => '+971 4 000 0000', 'email' => 'stay@grandharbor.test']);

    return $hotel->fresh();
}

function vrTemplate(Hotel $hotel, string $language, array $layout, bool $default = true, string $name = 'Standard'): VoucherTemplate
{
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => $name.' '.$language, 'language' => $language, 'is_default' => $default], vcImage(1240, 1754), null);

    return app(VoucherTemplateService::class)->saveLayout($template, $layout);
}

function vrBox(string $id, string $field, float $y, array $extra = []): array
{
    return ['id' => $id, 'field' => $field, 'x' => 0.05, 'y' => $y, 'w' => 0.9, 'h' => 0.05, 'font_size' => 11, 'color' => '#111111', 'bold' => false, 'align' => 'start', 'overflow' => 'shrink', 'text' => null, ...$extra];
}

// --- Built-in layout (US1) --------------------------------------------------

it('returns a one-page PDF with every voucher field and no total by default', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    $response = vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $pdf = $response->getContent();
    $text = vcPdfText($pdf);

    expect((new Parser)->parseContent($pdf)->getPages())->toHaveCount(1)
        ->and($text)->toContain('Grand Harbor Hotel')
        ->and($text)->toContain($reservation->reservation_id)
        ->and($text)->toContain('Confirmed')
        ->and($text)->toContain('Layla Haddad')
        ->and($text)->toContain('Omar Haddad')
        ->and($text)->toContain('10 December 2026')
        ->and($text)->toContain('13 December 2026')
        ->and($text)->toContain('Standard')
        ->and($text)->toContain('Late arrival around midnight')
        ->and($text)->toContain('7')
        ->and($text)->not->toContain('750.00');
});

it('shows the total only when asked', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    $text = vcPdfText(vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['show_total' => true])->assertOk()->getContent());

    expect($text)->toContain('750.00 USD');
});

it('prints Arabic labels right to left for an Arabic-speaking guest', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel, primary: ['preferred_language' => 'ar-SA']);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk();

    $data = app(VoucherDataBuilder::class)->fromReservation($reservation, VoucherLanguage::AR);
    $html = app(VoucherRenderer::class)->defaultHtml($data);

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain(__('voucher.title', [], 'ar'))
        ->and($html)->toContain(__('voucher.statuses.confirmed', [], 'ar'))
        ->and($html)->toContain('<bdo dir="ltr">'.$reservation->reservation_id.'</bdo>')
        ->and(EventLog::where('event_type', 'reservation.voucher_generated')->first()->context['language'])->toBe('ar');
});

it('prints English for a guest with another or no language', function (string $language) {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel, primary: ['preferred_language' => $language]);

    $text = vcPdfText(vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk()->getContent());

    expect($text)->toContain('Reservation Voucher');
})->with(['french' => 'fr', 'blank' => '']);

it('refuses a voucher for a cancelled or no-show reservation', function (string $status) {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel, ['status' => $status]);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertStatus(409);

    expect(EventLog::where('event_type', 'reservation.voucher_generated')->exists())->toBeFalse();
})->with(['cancelled', 'no_show']);

it('issues a voucher for a pending reservation showing its status', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel, ['status' => 'pending']);

    $text = vcPdfText(vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk()->getContent());

    expect($text)->toContain('Pending');
});

it('audits each generation with its options', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['show_total' => true, 'staff_note' => 'Pickup at 14:00'])->assertOk();

    $event = EventLog::where('event_type', 'reservation.voucher_generated')->sole();

    expect($event->subject_id)->toBe($reservation->id)
        ->and($event->actor_id)->toBe($hotel->owner->id)
        ->and($event->context)->toMatchArray(['language' => 'en', 'show_total' => true, 'voucher_template_id' => null])
        ->and($event->changes['staff_note'])->toBe('Pickup at 14:00');
});

it('downloads as an attachment when asked', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    $response = vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher?download=1")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('prints the room type but never a room number', function () {
    $hotel = vrHotel();
    $type = avType($hotel, 'Deluxe');
    [$room] = avRooms($hotel, $type, 1);
    $room->update(['room_number' => '4711']);
    $reservation = vcReservation($hotel);
    ReservationRoom::where('reservation_id', $reservation->id)->first()->update(['room_type_id' => $type->id, 'room_id' => $room->id]);

    $text = vcPdfText(vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk()->getContent());

    expect($text)->toContain('Deluxe')
        ->and($text)->not->toContain('4711');
});

it('never changes the reservation', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);
    $before = [Reservation::find($reservation->id)->toArray(), ReservationGuest::where('reservation_id', $reservation->id)->get()->toArray(), ReservationRoom::where('reservation_id', $reservation->id)->get()->toArray()];

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", [
        'overrides' => ['primary_guest_name' => 'ACME Travel LLC', 'arrival_date' => '2026-12-11', 'adults' => 4],
        'staff_note' => 'Note',
    ])->assertOk();

    expect([Reservation::find($reservation->id)->toArray(), ReservationGuest::where('reservation_id', $reservation->id)->get()->toArray(), ReservationRoom::where('reservation_id', $reservation->id)->get()->toArray()])->toBe($before);
});

// --- Isolation and permissions (US1) ----------------------------------------

it('answers not found for another hotel\'s reservation', function () {
    $hotel = vrHotel();
    $other = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, $other->owner, "/api/reservation/{$reservation->id}/voucher")->assertNotFound();
});

it('lets an employee generate a voucher only with the voucher permission', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER]), "/api/reservation/{$reservation->id}/voucher")->assertOk();
    vcPost($this, vcEmployee($hotel, [Permission::RESERVATIONS_VIEW]), "/api/reservation/{$reservation->id}/voucher")->assertForbidden();

    $noRole = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    vcPost($this, $noRole, "/api/reservation/{$reservation->id}/voucher")->assertForbidden();
});

// --- Template choice and overrides (US4) ------------------------------------

it('uses a named template and its language whatever the guest speaks', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);
    $arabic = vrTemplate($hotel, 'ar', [vrBox('g', 'guest.primary_name', 0.2)], default: false);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['template_id' => $arabic->id])->assertOk();

    expect(EventLog::where('event_type', 'reservation.voucher_generated')->sole()->context)
        ->toMatchArray(['voucher_template_id' => $arabic->id, 'language' => 'ar']);
});

it('answers not found for another hotel\'s or a deleted template', function () {
    $hotel = vrHotel();
    $other = vrHotel();
    $reservation = vcReservation($hotel);
    $foreign = vrTemplate($other, 'en', []);
    $deleted = vrTemplate($hotel, 'en', [], name: 'Old');
    $deleted->delete();

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['template_id' => $foreign->id])->assertNotFound();
    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['template_id' => $deleted->id])->assertNotFound();
});

it('prints every allowed override and recomputes the nights', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    $text = vcPdfText(vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['overrides' => [
        'primary_guest_name' => 'ACME Travel LLC',
        'other_guest_names' => ['Jane Roe'],
        'arrival_date' => '2026-12-11',
        'departure_date' => '2026-12-16',
        'room_lines' => ['Executive Suite'],
        'adults' => 4,
        'children' => 2,
        'children_ages' => [3, 11],
        'special_requests' => 'Quiet room please',
        'hotel_name' => 'Harbor Collection',
        'hotel_address' => '1 Marina Walk',
        'hotel_phone' => '+971 4 111 1111',
        'hotel_email' => 'desk@harbor.test',
    ]])->assertOk()->getContent());

    foreach (['ACME Travel LLC', 'Jane Roe', '11 December 2026', '16 December 2026', 'Executive Suite', 'Quiet room please', 'Harbor Collection', '1 Marina Walk', '+971 4 111 1111', 'desk@harbor.test', '3, 11'] as $expected) {
        expect($text)->toContain($expected);
    }

    $data = app(VoucherDataBuilder::class)->fromReservation($reservation, VoucherLanguage::EN, overrides: ['arrival_date' => '2026-12-11', 'departure_date' => '2026-12-16']);
    expect($data->value(VoucherField::STAY_NIGHTS))->toBe('5');
});

it('rejects overrides of fields that cannot be overridden', function (string $key) {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['overrides' => [$key => 'X']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("overrides.{$key}");
})->with(['reference', 'status', 'foo']);

it('rejects a displayed departure that is not after the displayed arrival', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['overrides' => ['departure_date' => '2026-12-10']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('overrides.departure_date');
});

it('says when a sent note has no place on the template', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);
    vrTemplate($hotel, 'en', [vrBox('g', 'guest.primary_name', 0.2)]);

    $response = vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", ['staff_note' => 'Pickup 14:00'])->assertOk();

    expect($response->headers->get('X-Voucher-Unplaced'))->toBe('voucher.staff_note');
});

it('records the validated overrides and note on the audit entry', function () {
    $hotel = vrHotel();
    $reservation = vcReservation($hotel);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher", [
        'overrides' => ['primary_guest_name' => 'ACME Travel LLC'],
        'staff_note' => 'For the visa office',
    ])->assertOk();

    expect(EventLog::where('event_type', 'reservation.voucher_generated')->sole()->changes)
        ->toBe(['overrides' => ['primary_guest_name' => 'ACME Travel LLC'], 'staff_note' => 'For the visa office']);
});
