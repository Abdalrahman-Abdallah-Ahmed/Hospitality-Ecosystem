<?php

use App\Enums\VoucherLanguage;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\ReservationGuest;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherRenderer;
use App\Services\Vouchers\VoucherTemplateService;
use App\Services\Vouchers\VoucherTextFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake(config('vouchers.disk'));
});

function rtFitter(VoucherLanguage $language = VoucherLanguage::EN): VoucherTextFitter
{
    return new VoucherTextFitter(app(VoucherRenderer::class)->newMpdf('A4', $language));
}

it('sizes the page from the image proportions with a 297 mm long side', function () {
    $renderer = app(VoucherRenderer::class);

    $round = fn (array $size) => array_map(fn (float $mm) => round($mm, 1), $size);

    expect($round($renderer->pageSize(2480, 3508)))->toBe([210.0, 297.0])
        ->and($round($renderer->pageSize(3508, 2480)))->toBe([297.0, 210.0])
        ->and($renderer->pageSize(3000, 3000))->toBe([297.0, 297.0]);

    $hotel = avHotel();
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => 'Wide', 'language' => 'en'], vcImage(3508, 2480), null);
    $pdf = $renderer->renderTemplate(app(VoucherDataBuilder::class)->sample(VoucherLanguage::EN), $template)->pdf;

    $box = (new Parser)->parseContent($pdf)->getPages()[0]->getDetails()['MediaBox'];

    expect(round($box[2] / 72 * 25.4))->toBe(297.0)
        ->and(round($box[3] / 72 * 25.4))->toBe(210.0);
});

it('shrinks a long value down to the minimum, then cuts it with an ellipsis', function () {
    $fitter = rtFitter();
    $long = str_repeat('Wellington Montgomery ', 30);

    $fitted = $fitter->fitText('notosans', false, 14, 60, 8, $long, 'shrink');

    expect($fitted['size'])->toBe(6.0)
        ->and(count($fitted['lines']))->toBe($fitter->maxLines(6, 8))
        ->and(end($fitted['lines']))->toEndWith('…');
});

it('keeps the size and cuts when the box truncates', function () {
    $fitted = rtFitter()->fitText('notosans', false, 14, 60, 8, str_repeat('Wellington Montgomery ', 30), 'truncate');

    expect($fitted['size'])->toBe(14.0)
        ->and($fitted['lines'])->toHaveCount(1)
        ->and($fitted['lines'][0])->toEndWith('…');
});

it('leaves a value that fits unchanged', function () {
    $fitted = rtFitter()->fitText('notosans', true, 12, 80, 10, 'Layla Haddad', 'shrink');

    expect($fitted)->toBe(['size' => 12.0, 'lines' => ['Layla Haddad']]);
});

it('ends a list that cannot fit with "+N more", and the counts add up', function (VoucherLanguage $language) {
    $guests = array_map(fn ($i) => "Guest number {$i}", range(1, 10));

    $fitted = rtFitter($language)->fitList('notosans', false, 12, 60, 12, $guests, $language);
    $last = end($fitted['lines']);
    $shown = count($fitted['lines']) - 1;

    expect($fitted['size'])->toBe(6.0)
        ->and($last)->toBe(__('voucher.more', ['count' => 10 - $shown], $language->value))
        ->and(array_slice($fitted['lines'], 0, $shown))->toBe(array_slice($guests, 0, $shown));
})->with([VoucherLanguage::EN, VoucherLanguage::AR]);

it('keeps a list that fits as it is', function () {
    $fitted = rtFitter()->fitList('notosans', false, 10, 80, 30, ['Layla Haddad', 'Omar Haddad'], VoucherLanguage::EN);

    expect($fitted)->toBe(['size' => 10.0, 'lines' => ['Layla Haddad', 'Omar Haddad']]);
});

it('mirrors start and end alignment on Arabic templates', function () {
    $renderer = app(VoucherRenderer::class);

    expect($renderer->cssAlign('start', false))->toBe('left')
        ->and($renderer->cssAlign('end', false))->toBe('right')
        ->and($renderer->cssAlign('start', true))->toBe('right')
        ->and($renderer->cssAlign('end', true))->toBe('left')
        ->and($renderer->cssAlign('center', true))->toBe('center');
});

it('places each box absolutely at its fraction of the page and hides overflow', function () {
    $renderer = app(VoucherRenderer::class);
    $layout = [['id' => 'g', 'field' => 'guest.primary_name', 'x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.05, 'font_size' => 12, 'color' => '#112233', 'bold' => true, 'align' => 'start', 'overflow' => 'shrink']];

    $html = $renderer->templateHtml(app(VoucherDataBuilder::class)->sample(VoucherLanguage::AR), $layout, 210, 297, '/tmp/render.jpg', rtFitter(VoucherLanguage::AR));

    expect($html)->toContain('position: absolute')
        ->and($html)->toContain('overflow: hidden')
        ->and($html)->toContain('left: 21mm; top: 59.4mm; width: 105mm; height: 14.85mm;')
        ->and($html)->toContain('color: #112233; font-weight: bold; text-align: right; direction: rtl;')
        ->and($html)->toContain('background-image: url("/tmp/render.jpg")');
});

it('uses the hotel default template for the guest language, and the built-in layout once it is gone', function () {
    $hotel = avHotel();
    $reservation = vcReservation($hotel);
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => 'Main', 'language' => 'en', 'is_default' => true], vcImage(1240, 1754), null);

    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk();
    $template->delete();
    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk();

    expect(EventLog::where('event_type', 'reservation.voucher_generated')->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($e) => $e->context['voucher_template_id'])->all())
        ->toEqualCanonicalizing([$template->id, null]);
});

it('uploads and renders the largest template within the time and memory budget', function () {
    $hotel = avHotel();
    $reservation = vcReservation($hotel, rooms: 10);
    foreach (range(1, 8) as $i) {
        $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => "Guest{$i}", 'last_name' => 'Traveller', 'external_id' => 'ext-'.Str::random(8), 'channel' => 'booking_com']);
        ReservationGuest::create(['hotel_id' => $hotel->id, 'reservation_id' => $reservation->id, 'guest_id' => $guest->id, 'is_primary' => false]);
    }
    $image = vcImage(6000, 2000);
    $layout = [
        ['id' => 'g', 'field' => 'guests.all', 'x' => 0.05, 'y' => 0.2, 'w' => 0.4, 'h' => 0.3, 'font_size' => 14, 'color' => '#000000', 'bold' => false, 'align' => 'start', 'overflow' => 'shrink'],
        ['id' => 'r', 'field' => 'rooms.lines', 'x' => 0.5, 'y' => 0.2, 'w' => 0.4, 'h' => 0.3, 'font_size' => 14, 'color' => '#000000', 'bold' => false, 'align' => 'start', 'overflow' => 'shrink'],
    ];

    // Measured as growth over the starting point: a long-lived (parallel)
    // worker already holds memory from earlier tests.
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $start = hrtime(true);
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => 'Big', 'language' => 'en'], $image, null);
    $uploadSeconds = (hrtime(true) - $start) / 1e9;
    $uploadPeak = memory_get_peak_usage() - $base;

    app(VoucherTemplateService::class)->saveLayout($template, $layout);
    $data = app(VoucherDataBuilder::class)->fromReservation($reservation->fresh(), VoucherLanguage::EN);

    memory_reset_peak_usage();
    $base = memory_get_usage();
    $start = hrtime(true);
    $pdf = app(VoucherRenderer::class)->renderTemplate($data, VoucherTemplate::find($template->id))->pdf;
    $renderSeconds = (hrtime(true) - $start) / 1e9;
    $renderPeak = memory_get_peak_usage() - $base;

    expect($pdf)->toStartWith('%PDF')
        ->and($uploadSeconds)->toBeLessThan(5)
        ->and($renderSeconds)->toBeLessThan(5)
        ->and($uploadPeak)->toBeLessThan(256 * 1024 * 1024, 'upload used '.round($uploadPeak / 1048576).' MB')
        ->and($renderPeak)->toBeLessThan(256 * 1024 * 1024, 'render used '.round($renderPeak / 1048576).' MB');
})->group('slow');
