<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @return array{0: User, 1: Hotel}
 */
function whatsappDeviceOwner(array $overrides = []): array
{
    $user = User::factory()->create($overrides);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Demo Hotel',
        'slug' => 'demo-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);

    return [$user->fresh(), $hotel];
}

function pairWith(string $token, string $waUserId = 'EG.1586110233134033'): array
{
    return [
        'phone_number' => '201151793758',
        'wa_user_id' => $waUserId,
        'token' => $token,
    ];
}

// connect

it('creates an expiring whatsapp pairing code for an authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/connect');

    $response->assertOk()
        ->assertJsonPath('message', 'WhatsApp device token created successfully.')
        ->assertJsonPath('code', 200);

    expect($response->json('body.token'))->toBeString()->not->toBe('');

    $code = PersonalAccessToken::findToken($response->json('body.token'));
    expect($code->name)->toBe(WhatsAppDevice::PAIRING_TOKEN_NAME)
        ->and($code->expires_at->isFuture())->toBeTrue()
        ->and($code->expires_at->lessThanOrEqualTo(now()->addMinutes(15)))->toBeTrue();
});

it('does not accept a pairing code as an api credential', function () {
    [$user] = whatsappDeviceOwner();

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->withToken(pairingCodeFor($user))
        ->getJson('/api/user')
        ->assertUnauthorized();

    // The same user's login token still works, so the 401 above is the
    // pairing-code refusal and not a broken request.
    $this->withHeader('X-API-KEY', 'test-api-key')
        ->withToken($user->createToken('api-token')->plainTextToken)
        ->getJson('/api/user')
        ->assertOk();
});

// pair

it('pairs a whatsapp device for a user identified by a request-body token', function () {
    [$user, $hotel] = whatsappDeviceOwner();
    $token = pairingCodeFor($user);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith($token));

    $response->assertStatus(200)
        ->assertJsonPath('message', 'WhatsApp device paired successfully.')
        ->assertJsonPath('code', 200)
        ->assertJsonPath('body.user_id', $user->id)
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.phone_number', '201151793758')
        ->assertJsonPath('body.wa_user_id', 'EG.1586110233134033')
        ->assertJsonPath('body.status', 'active');

    expect(WhatsAppDevice::where('user_id', $user->id)->exists())->toBeTrue();
});

it('revokes the pairing code once it has been redeemed', function () {
    [$user] = whatsappDeviceOwner();
    $token = pairingCodeFor($user);

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith($token))
        ->assertOk();

    expect(PersonalAccessToken::findToken($token))->toBeNull();

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith($token, 'EG.9999999999999999'))
        ->assertStatus(201)
        ->assertJsonPath('message', 'Invalid token.');
});

it('rejects pairing when the token is invalid', function () {
    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith('invalid-token'));

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Invalid token.')
        ->assertJsonPath('code', 201)
        ->assertJsonPath('body', null);
});

it('rejects pairing with an expired code', function () {
    [$user] = whatsappDeviceOwner();

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith(pairingCodeFor($user, now()->subMinute())))
        ->assertStatus(201)
        ->assertJsonPath('message', 'Invalid token.');

    expect(WhatsAppDevice::count())->toBe(0);
});

it('rejects pairing with a login token instead of a pairing code', function () {
    [$user] = whatsappDeviceOwner();

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith($user->createToken('api-token')->plainTextToken))
        ->assertStatus(201)
        ->assertJsonPath('message', 'Invalid token.');

    expect(WhatsAppDevice::count())->toBe(0);
});

it('rejects pairing when the token owner has no hotel', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', pairWith(pairingCodeFor($user)));

    $response->assertStatus(202)
        ->assertJsonPath('message', 'User is not associated with any hotel.')
        ->assertJsonPath('code', 202)
        ->assertJsonPath('body', null);
});

it('rejects pairing when the user already has a paired whatsapp device', function () {
    [$user, $hotel] = whatsappDeviceOwner();
    $token = pairingCodeFor($user);

    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', [
            'phone_number' => '201199999999',
            'wa_user_id' => 'EG.9999999999999999',
            'token' => $token,
        ]);

    $response->assertStatus(203)
        ->assertJsonPath('message', 'User already has a paired WhatsApp device.')
        ->assertJsonPath('code', 203)
        ->assertJsonPath('body', null);

    expect(WhatsAppDevice::count())->toBe(1);
});

// check-paired

it('requires authentication for check-paired', function () {
    $this->withHeader('X-API-KEY', 'test-api-key')
        ->getJson('/api/check-paired?phone_number=201151793758')
        ->assertUnauthorized();
});

it('reports an active paired device for check-paired', function () {
    [$user, $hotel] = whatsappDeviceOwner();
    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user, 'sanctum')
        ->getJson('/api/check-paired?phone_number=201151793758');

    $response->assertStatus(200)
        ->assertJsonPath('message', 'User has a paired WhatsApp device.')
        ->assertJsonPath('body.paired', true)
        ->assertJsonPath('body.device.phone_number', '201151793758');
});

it('reports a paired but inactive device for check-paired', function () {
    [$user, $hotel] = whatsappDeviceOwner();
    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'pending',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user, 'sanctum')
        ->getJson('/api/check-paired?phone_number=201151793758');

    $response->assertStatus(202)
        ->assertJsonPath('message', 'User has a paired WhatsApp device, but it is not active.')
        ->assertJsonPath('body.paired', true);
});

it('reports not paired for an unpaired phone number in the caller own hotel', function () {
    [$caller, $hotel] = whatsappDeviceOwner();
    $admin = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($caller, 'sanctum')
        ->getJson('/api/check-paired?phone_number=201151793758');

    $response->assertStatus(201)
        ->assertJsonPath('message', 'User not paired.')
        ->assertJsonPath('body.paired', false)
        ->assertJsonPath('body.user_name', $admin->name);
});

it('does not reveal a user or device belonging to another hotel via check-paired', function () {
    [$caller] = whatsappDeviceOwner();
    [$stranger, $otherHotel] = whatsappDeviceOwner(['phone_number' => '201151793758']);
    WhatsAppDevice::create([
        'user_id' => $stranger->id,
        'phone_number' => '201151793758',
        'hotel_id' => $otherHotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($caller, 'sanctum')
        ->getJson('/api/check-paired?phone_number=201151793758');

    $response->assertStatus(201)
        ->assertJsonPath('body.paired', false)
        ->assertJsonPath('body.user_name', null)
        ->assertJsonPath('body.user_role', null);
});
