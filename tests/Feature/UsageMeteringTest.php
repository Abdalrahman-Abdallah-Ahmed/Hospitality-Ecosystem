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
use App\Services\Metering\UsageReport;
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
        ->and($account['seats']['hotels']['used'])->toBe(1)
        // Published as `hotels`; the stored code stays `properties`.
        ->and($account['seats']['hotels']['code'])->toBe('properties')
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

it('returns the calling account its own consumption', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel('Own Hotel');
    $admin->update(['hotel_id' => $hotel->id]);

    metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 9);
    metering()->recordForHotel($hotel, MeterFeature::BOOKINGS_CREATED, quantity: 2);
    metering()->recountSeats($hotel->hotelGroup);

    $response = $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk();

    expect($response->json('body.hotel_group_id'))->toBe($hotel->hotel_group_id)
        ->and($response->json('body.features.ai_messages.used'))->toBe(9)
        ->and($response->json('body.features.ai_messages.unit'))->toBe('messages')
        ->and($response->json('body.features.bookings_created.used'))->toBe(2)
        ->and($response->json('body.seats.hotels.used'))->toBe(1)
        ->and($response->json('body.seats.hotels.label'))->toBe('Hotels')
        // The same explicit gaps the super-admin report names, so a hotel is
        // never shown a zero that actually means "not measured".
        ->and($response->json('body.not_measured.recommendations_delivered'))->toContain('not measured');
});

it('never shows one account another account\'s consumption', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $ours = meteringHotel('Ours');
    $admin->update(['hotel_id' => $ours->id]);

    $theirs = meteringHotel('Theirs');

    metering()->recordForHotel($ours, MeterFeature::AI_MESSAGES, quantity: 3);
    metering()->recordForHotel($theirs, MeterFeature::AI_MESSAGES, quantity: 500);

    $response = $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        // A supplied account id must not be honoured. The endpoint takes the
        // account from the token precisely so that this cannot work.
        ->getJson('/api/usage?hotel_group_id='.$theirs->hotel_group_id)
        ->assertOk();

    expect($response->json('body.hotel_group_id'))->toBe($ours->hotel_group_id)
        ->and($response->json('body.features.ai_messages.used'))->toBe(3);
});

it('does not expose what the account cost us to serve', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 4);

    $body = $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body');

    // Provider cost is our cost of goods. An account that can see what it
    // costs to serve can compute our margin on its own contract.
    //
    // Matched against money-specific tokens rather than the bare word "cost",
    // because the feature taxonomy legitimately calls a meter a `cost_driver`
    // — that is a category name, not a figure. Scanning for "cost" alone
    // would fail on a word that carries no monetary information at all.
    $encoded = strtolower(json_encode($body));

    foreach (['cost_usd', 'cost_eur', 'ai_cost', 'cost_per', 'margin', 'contract', '_price', 'usd', 'eur'] as $forbidden) {
        expect(str_contains($encoded, $forbidden))->toBeFalse(
            "the tenant usage response must not mention [{$forbidden}]"
        );
    }
});

it('blocks an employee from reading account consumption', function () {
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create();
    $hotel = meteringHotel();
    $employee->update(['hotel_id' => $hotel->id]);

    // An employee works in a hotel; they do not represent the customer, and
    // account-level consumption is commercial information about the account.
    $this->withHeaders(meteringHeaders())->actingAs($employee->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertStatus(403);
});

it('tells an admin with no account that they belong to none', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();

    $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not belong to any account.');
});

it('lists every AI resource, including the ones with no activity', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    // Only one kind of usage happened this period.
    metering()->recordForHotel($hotel, MeterFeature::AI_MESSAGES, quantity: 5);

    $features = $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.features');

    // A feature with no events reads an explicit 0 rather than being absent.
    // Omitting it would make "used nothing" indistinguishable from "we do not
    // track that", and force the frontend to carry its own copy of the
    // catalogue to render a complete list.
    expect($features['ai_messages']['used'])->toBe(5)
        ->and($features['ai_insights_generated']['used'])->toBe(0)
        ->and($features['recommendations_generated']['used'])->toBe(0)
        ->and($features['embeddings_generated']['used'])->toBe(0)
        ->and($features['embeddings_generated']['label'])->toBe('Knowledge base indexing')
        ->and($features['embeddings_generated']['category'])->toBe('cost_driver')
        ->and($features['bookings_created']['category'])->toBe('value_signal');

    // Except where nothing is counting: null with measured=false, because
    // zero would be a claim we cannot support.
    expect($features['recommendations_delivered']['used'])->toBeNull()
        ->and($features['recommendations_delivered']['measured'])->toBeFalse()
        ->and($features['conversations_handled']['used'])->toBeNull()
        ->and($features['ai_messages']['measured'])->toBeTrue();

    // Seats are not event-derived and are reported separately.
    expect($features)->not->toHaveKey('properties')
        ->and($features)->not->toHaveKey('users');
});

it('counts a user as a seat when they are linked to a hotel after being created', function () {
    // The ordinary registration sequence: the user exists before the hotel
    // does, and is attached to it afterwards. Counting only on `created`
    // would leave this account reading zero users for good.
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();

    expect(UsageCounter::where('hotel_group_id', $hotel->hotel_group_id)
        ->where('feature_code', MeterFeature::USERS->value)->value('used'))->toBe(0);

    $admin->update(['hotel_id' => $hotel->id]);

    expect(UsageCounter::where('hotel_group_id', $hotel->hotel_group_id)
        ->where('feature_code', MeterFeature::USERS->value)->value('used'))->toBe(1);
});

it('corrects both accounts when a user moves between them', function () {
    $from = meteringHotel('From');
    $to = meteringHotel('To');

    $user = User::factory()->role(UserRole::ADMIN)->create();
    $user->update(['hotel_id' => $from->id]);

    $seats = fn (string $groupId) => UsageCounter::where('hotel_group_id', $groupId)
        ->where('feature_code', MeterFeature::USERS->value)->value('used');

    expect($seats($from->hotel_group_id))->toBe(1)
        ->and($seats($to->hotel_group_id))->toBe(0);

    $user->update(['hotel_id' => $to->id]);

    // The account they left must come down. Recounting only the account
    // gained would leave the old one permanently overstated — the same
    // failure as incrementing, which is why seats are recounted at all.
    expect($seats($from->hotel_group_id))->toBe(0)
        ->and($seats($to->hotel_group_id))->toBe(1);
});

it('corrects both accounts when a hotel is reassigned to another group', function () {
    $hotel = meteringHotel('Movable');
    $original = $hotel->hotel_group_id;
    $target = HotelGroup::create(['name' => 'Real Group', 'slug' => 'real-group-'.uniqid()]);

    $seats = fn (string $groupId) => UsageCounter::where('hotel_group_id', $groupId)
        ->where('feature_code', MeterFeature::PROPERTIES->value)->value('used');

    expect($seats($original))->toBe(1);

    // Exactly the move HotelGroup::singlePropertyFor() anticipates: the
    // placeholder group is left behind empty when a real one is formed.
    $hotel->update(['hotel_group_id' => $target->id]);

    expect($seats($original))->toBe(0)
        ->and($seats($target->id))->toBe(1);
});

it('publishes the properties seat as hotels, without renaming the permanent code', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = meteringHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    $seats = $this->withHeaders(meteringHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.seats');

    // Published as `hotels`, because "properties" reads as real estate to
    // anyone outside hospitality. The STORED code stays `properties` — it is
    // written into meter_events and the history stops adding up if it
    // changes — so it travels alongside as `code` and the figure remains
    // traceable to the meter behind it.
    expect($seats)->toHaveKey('hotels')
        ->and($seats)->not->toHaveKey('properties')
        ->and($seats['hotels']['code'])->toBe('properties')
        ->and($seats['hotels']['label'])->toBe('Hotels')
        ->and($seats['hotels']['unit'])->toBe('hotels')
        ->and($seats['hotels']['used'])->toBe(1)
        ->and($seats['hotels']['measured'])->toBeTrue();

    // Every seat is present, so a dashboard need not know the catalogue.
    expect($seats)->toHaveKeys(['hotels', 'users', 'guests', 'stays']);
});

it('says a seat was never counted rather than reporting it as zero', function () {
    // Events suppressed, exactly as DatabaseSeeder does — so no recount ever
    // ran and no counter row exists.
    $group = HotelGroup::create(['name' => 'Quiet Group', 'slug' => 'quiet-'.uniqid()]);

    $hotel = Hotel::withoutEvents(fn () => Hotel::create([
        'name' => 'Unrecounted',
        'slug' => 'unrecounted-'.uniqid(),
        'currency' => 'USD',
        'hotel_group_id' => $group->id,
    ]));

    $described = app(UsageReport::class)->describe($group, collect(), collect());

    // The account owns a hotel. Reporting 0 would be a worse answer than
    // admitting nothing has counted yet.
    expect($described['seats']['hotels']['used'])->toBeNull()
        ->and($described['seats']['hotels']['measured'])->toBeFalse();
});
