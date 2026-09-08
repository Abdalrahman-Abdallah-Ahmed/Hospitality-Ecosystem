<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\MeterFeature;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\MeterEvent;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function meteringHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function meteringHotel(string $name = 'Metering Hotel'): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => 'metering-'.uniqid(),
        'currency' => 'USD',
    ]);
}

function metering(): MeteringService
{
    return app(MeteringService::class);
}

it('does not double count when the same idempotency key is submitted twice', function () {
    $hotel = meteringHotel();

    $first = metering()->recordForHotel(
        hotel: $hotel,
        feature: MeterFeature::AI_MESSAGES,
        idempotencyKey: 'whatsapp:msg-1',
    );

    $second = metering()->recordForHotel(
        hotel: $hotel,
        feature: MeterFeature::AI_MESSAGES,
        idempotencyKey: 'whatsapp:msg-1',
    );

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(MeterEvent::count())->toBe(1);

    $counter = UsageCounter::where('feature_code', MeterFeature::AI_MESSAGES->value)->first();

    // The replay must not reach the counter either. A rejected event that
    // still incremented would be the worst of both.
    expect($counter->used)->toBe(1);
});

it('rebuilds a deliberately corrupted counter correctly from events', function () {
    $hotel = meteringHotel();

    foreach (range(1, 3) as $i) {
        metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 2);
    }

    DB::table('usage_counters')
        ->where('feature_code', MeterFeature::AI_MESSAGES->value)
        ->update(['used' => 999]);

    $discrepancies = metering()->rebuild();

    $counter = UsageCounter::where('feature_code', MeterFeature::AI_MESSAGES->value)->first();

    expect($counter->used)->toBe(6)
        ->and($discrepancies)->toHaveCount(1)
        ->and($discrepancies[0]['was'])->toBe(999)
        ->and($discrepancies[0]['now'])->toBe(6);
});

it('recounts seats rather than incrementing them', function () {
    $hotel = meteringHotel();
    $account = $hotel->hotelGroup;

    metering()->recountSeats($account);

    expect(seatCount($account, MeterFeature::PROPERTIES))->toBe(1);

    // A second hotel in the same group, then removed again. If seats were
    // incremented the count would stay at 2 forever.
    $second = Hotel::create([
        'hotel_group_id' => $account->id,
        'name' => 'Second Property',
        'slug' => 'second-'.uniqid(),
        'currency' => 'USD',
    ]);

    expect(seatCount($account, MeterFeature::PROPERTIES))->toBe(2);

    $second->delete();

    expect(seatCount($account, MeterFeature::PROPERTIES))->toBe(1);
});

it('refuses to record a seat as an event', function () {
    $hotel = meteringHotel();

    expect(fn () => metering()->recordForHotel($hotel, MeterFeature::PROPERTIES))
        ->toThrow(RuntimeException::class);
});

it('still answers the guest when metering throws', function () {
    $hotel = meteringHotel();
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
        'phone_number' => '+201000000000',
    ]);

    // A partial mock: recordForHotel explodes, but the real safely() still
    // wraps it — which is the thing under test.
    $metering = Mockery::mock(MeteringService::class)->makePartial();
    $metering->shouldReceive('recordForHotel')->andThrow(new RuntimeException('metering exploded'));
    app()->instance(MeteringService::class, $metering);

    $whatsApp = Mockery::mock(WhatsAppMessageService::class);
    $whatsApp->shouldReceive('send')->once()->with('+201000000000', 'Of course, I can help with that.');
    app()->instance(WhatsAppMessageService::class, $whatsApp);

    GuestConciergeAgent::fake(['Of course, I can help with that.']);

    (new ProcessInboundWhatsAppMessageJob(
        phoneNumber: '+201000000000',
        messageText: 'Can I book a table?',
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $hotel,
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    // No event recorded, and no exception escaped: the guest got their reply.
    expect(MeterEvent::count())->toBe(0);
});

it('assigns an event at 23:59 on the last day to the closing period', function () {
    $hotel = meteringHotel();

    $closing = metering()->recordForHotel(
        $hotel,
        MeterFeature::AI_MESSAGES,
        occurredAt: Carbon\Carbon::parse('2026-08-31 23:59:59'),
    );

    $opening = metering()->recordForHotel(
        $hotel,
        MeterFeature::AI_MESSAGES,
        occurredAt: Carbon\Carbon::parse('2026-09-01 00:00:00'),
    );

    expect($closing->period_start->toDateString())->toBe('2026-08-01')
        ->and($opening->period_start->toDateString())->toBe('2026-09-01');
});

it('records one event with quantity N for a bulk import, not N events', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel('Import Hotel');
    $admin->update(['hotel_id' => $hotel->id]);

    $columns = ['external_reference', 'item_name', 'line_total', 'currency', 'transacted_at'];
    $rows = [];

    foreach (range(1, 5) as $i) {
        $rows[] = "REF-{$i},Spa treatment,60,USD,2026-08-15 10:00";
    }

    $path = tempnam(sys_get_temp_dir(), 'metering').'.csv';
    file_put_contents($path, implode("\n", [implode(',', $columns), ...$rows]));

    $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->postJson('/api/transaction/import', [
            'file' => new UploadedFile($path, 'transactions.csv', 'text/csv', null, true),
        ])->assertOk();

    $events = MeterEvent::where('feature_code', MeterFeature::TRANSACTION_ROWS_IMPORTED->value)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->quantity)->toBe(5);
});

it('stores no monetary value on a meter event', function () {
    // A schema assertion, and the one that protects the design: a meter event
    // records what happened, never what it was worth. The moment a price
    // lands on this table every historical row is locked to the pricing that
    // applied when it was written.
    $columns = Schema::getColumnListing('meter_events');

    $monetary = array_filter(
        $columns,
        fn (string $column) => str_contains($column, 'price')
            || str_contains($column, 'cost')
            || str_contains($column, 'amount')
            || str_contains($column, 'charge')
            || str_contains($column, 'currency')
    );

    expect($monetary)->toBeEmpty()
        ->and($columns)->not->toContain('subscription_id');
});

it('keeps meter events append only', function () {
    $hotel = meteringHotel();
    $event = metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES);

    expect(fn () => $event->update(['quantity' => 99]))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

it('meters an advisor chat message against the right account', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel('Advisor Hotel');
    $admin->update(['hotel_id' => $hotel->id]);

    AdminAdvisorAgent::fake(['Here is some advice.']);

    $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'What now?'])
        ->assertOk();

    $event = MeterEvent::where('feature_code', MeterFeature::AI_MESSAGES->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->hotel_group_id)->toBe($hotel->hotel_group_id)
        ->and($event->hotel_id)->toBe($hotel->id);
});

it('blocks a non super admin from the usage report', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertStatus(403);
});

it('reports usage per account per feature, and names what it cannot measure', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $hotel = meteringHotel('Reported Hotel');

    metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 7);
    metering()->recordForHotel($hotel, MeterFeature::RECOMMENDATIONS_GENERATED, quantity: 4);
    metering()->recountSeats($hotel->hotelGroup);

    $response = $this->withHeaders(meteringHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertOk();

    $account = collect($response->json('body.accounts'))
        ->firstWhere('hotel_group_id', $hotel->hotel_group_id);

    expect($account['features']['ai_messages']['used'])->toBe(7)
        ->and($account['features']['ai_messages']['unit'])->toBe('messages')
        ->and($account['seats']['properties'])->toBe(1)
        ->and($account['recommendations']['generated'])->toBe(4)
        // An honest gap, not a zero: nothing measures delivery yet.
        ->and($account['recommendations']['delivered'])->toBeNull()
        ->and($account['recommendations']['delivered_basis'])->toBe('not measured')
        ->and($response->json('body.not_measured'))
        ->toHaveKeys(['recommendations_delivered', 'conversations_handled']);
});

it('does not gate any request in this phase', function () {
    // WP-9 is deferred. Half an enforcement layer is worse than none, because
    // people start to trust it — so nothing here may ever return 402.
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 1_000_000);

    AdminAdvisorAgent::fake(['Still answering.']);

    $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Still there?'])
        ->assertOk();
});

function seatCount(HotelGroup $account, MeterFeature $feature): int
{
    app(MeteringService::class)->recountSeats($account);

    return (int) UsageCounter::where('hotel_group_id', $account->id)
        ->where('feature_code', $feature->value)
        ->value('used');
}
