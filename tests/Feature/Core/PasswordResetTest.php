<?php

use App\Models\EventLog;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/*
| Spec 023: POST /api/reset-password, for a new user's first password link
| (72 hours) and any other reset link (60 minutes).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

const NEW_PASSWORD = 'A-new-Password1!';

function resetRequest($test, User $user, string $token, array $overrides = [])
{
    return $test->withHeaders(apiHeaders())->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        ...$overrides,
    ]);
}

it('accepts a first-password link inside 72 hours, and only once', function () {
    $user = User::factory()->create();
    $token = Password::broker('invitations')->createToken($user);

    $this->travel(71)->hours();

    resetRequest($this, $user, $token)->assertOk();

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();

    resetRequest($this, $user, $token, ['password' => 'Another-Pass1!', 'password_confirmation' => 'Another-Pass1!'])
        ->assertStatus(422);

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('refuses a first-password link after 72 hours', function () {
    $user = User::factory()->create();
    $hash = $user->password;
    $token = Password::broker('invitations')->createToken($user);

    $this->travel(73)->hours();

    resetRequest($this, $user, $token)->assertStatus(422);

    expect($user->fresh()->password)->toBe($hash);
});

it('gives a normal reset link 60 minutes, not the longer life', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->travel(61)->minutes();

    resetRequest($this, $user, $token)->assertStatus(422);

    $fresh = Password::broker('users')->createToken($user);

    $this->travel(59)->minutes();

    resetRequest($this, $user, $fresh)->assertOk();
});

it('answers every failure with the same message, so it never reveals an email', function () {
    $user = User::factory()->create();
    $expired = Password::broker('users')->createToken($user);
    $this->travel(61)->minutes();

    $messages = [
        resetRequest($this, $user, 'wrong-token')->assertStatus(422)->json('message'),
        resetRequest($this, $user, $expired)->assertStatus(422)->json('message'),
        $this->withHeaders(apiHeaders())->postJson('/api/reset-password', [
            'token' => 'wrong-token',
            'email' => 'nobody@example.com',
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])->assertStatus(422)->json('message'),
    ];

    expect(array_unique($messages))->toBe(['This password reset link is invalid or has expired.']);
});

it('clears tokens, device and both pending links on success, and writes no platform event', function () {
    $hotel = makeHotel();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $user->createToken('api-token');
    WhatsAppDevice::create(['user_id' => $user->id, 'phone_number' => '201000000003', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-3', 'status' => 'active']);
    Password::broker('invitations')->createToken($user);
    $token = Password::broker('users')->createToken($user);

    resetRequest($this, $user, $token)->assertOk();

    expect($user->tokens()->count())->toBe(0)
        ->and(WhatsAppDevice::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('user_invitation_tokens')->count())->toBe(0)
        ->and(EventLog::withoutGlobalScopes()->where('event_type', 'like', 'platform.%')->count())->toBe(0);
});

it('stops a pending link working when the user\'s email changes', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = Password::broker('users')->createToken($user);

    $user->update(['email' => 'new@example.com']);

    $this->withHeaders(apiHeaders())->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'old@example.com',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertStatus(422);

    resetRequest($this, $user->fresh(), $token)->assertStatus(422);
});

it('validates the new password', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    resetRequest($this, $user, $token, ['password_confirmation' => 'Something-else1!'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
    resetRequest($this, $user, $token, ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('needs the API key', function () {
    $this->postJson('/api/reset-password', [])->assertStatus(401);
});

it('rate limits repeated attempts like login', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        resetRequest($this, $user, 'wrong-token')->assertStatus(422);
    }

    resetRequest($this, $user, 'wrong-token')->assertStatus(429);
});
