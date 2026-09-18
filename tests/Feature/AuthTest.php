<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('registers a new user through the api', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Alice Example',
            'email' => 'alice@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'hotel' => [
                'name' => 'Alice Hotel',
                'city' => 'Cairo',
            ],
        ]);

    $response->assertStatus(201);
    expect(User::where('email', 'alice@example.com')->exists())->toBeTrue();
});

it('logs in an existing user through the api', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $user = User::factory()->create([
        'email' => 'bob@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.user.email', $user->email);
});

it('returns the staff role and effective permissions on login', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Login Hotel',
        'slug' => 'login-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);
    $role = StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Housekeeping',
        'permissions' => [Permission::ROOMS_VIEW->value],
    ]);
    $employee = User::factory()->create([
        'email' => 'dana@example.com',
        'password' => bcrypt('Password123!'),
        'hotel_id' => $hotel->id,
        'staff_role_id' => $role->id,
    ]);

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/login', [
            'email' => $employee->email,
            'password' => 'Password123!',
        ])
        ->assertOk()
        ->assertJsonPath('body.user.staff_role.name', 'Housekeeping')
        ->assertJsonPath('body.user.permissions', [Permission::ROOMS_VIEW->value]);
});

it('throttles repeated failed logins for the same email', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $user = User::factory()->create(['email' => 'carol@example.com']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withHeader('X-API-KEY', 'test-api-key')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);
    }

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
});

it('throttles failed logins sprayed across many emails from one ip', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    foreach (range(1, 20) as $attempt) {
        $this->withHeader('X-API-KEY', 'test-api-key')
            ->postJson('/api/login', ['email' => "user{$attempt}@example.com", 'password' => 'wrong-password'])
            ->assertStatus(401);
    }

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/login', ['email' => 'another@example.com', 'password' => 'wrong-password'])
        ->assertStatus(429);
});

it('registers a user through the api endpoint', function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Api User',
            'email' => 'api@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'hotel' => [
                'name' => 'Api Hotel',
                'city' => 'Cairo',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.user.email', 'api@example.com')
        ->assertJsonPath('body.user.role', UserRole::ADMIN->value)
        ->assertJsonPath('body.user.staff_role', null)
        ->assertJsonPath('body.user.permissions', array_map(
            fn (Permission $permission) => $permission->value,
            Permission::cases(),
        ));

    expect(User::where('email', 'api@example.com')->exists())->toBeTrue();
});

it('emails a welcome notification to the user who registers', function () {
    Notification::fake();
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Welcome User',
            'email' => 'welcome@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'hotel' => [
                'name' => 'Welcome Hotel',
                'city' => 'Cairo',
            ],
        ])
        ->assertStatus(201);

    $user = User::where('email', 'welcome@example.com')->firstOrFail();

    Notification::assertSentTo(
        $user,
        WelcomeNotification::class,
        fn (WelcomeNotification $notification, array $channels) => $notification->hotelName === 'Welcome Hotel'
            && $channels === ['mail'],
    );
    Notification::assertCount(1);
});

it('does not send a welcome notification when registration fails validation', function () {
    Notification::fake();
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', ['email' => 'not-an-email'])
        ->assertStatus(422);

    Notification::assertNothingSent();
});
