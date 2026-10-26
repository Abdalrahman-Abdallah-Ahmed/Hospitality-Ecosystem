<?php

use App\Enums\UserRole;
use App\Models\AiModelPrice;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
| Spec 017 (FR-030, research R15): an empty database, migrated and seeded as
| production would be, holds a usable platform and no demo data; demo data
| comes only when asked for.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function seedAs(string $environment): void
{
    $previous = app()->environment();
    app()['env'] = $environment;

    try {
        // Directly rather than through db:seed, which asks for confirmation
        // in production; __invoke still applies WithoutModelEvents.
        app(DatabaseSeeder::class)->setContainer(app())->__invoke();
    } finally {
        app()['env'] = $previous;
    }
}

it('seeds production with the platform only: a super admin and model prices, no demo hotel', function () {
    config(['seeding.demo' => false, 'seeding.super_admin.password' => 'a-long-random-password', 'seeding.super_admin.email' => 'owner@example.test']);

    seedAs('production');

    $admin = User::where('email', 'owner@example.test')->sole();

    expect($admin->role)->toBe(UserRole::SUPER_ADMIN)
        ->and(Hash::check('a-long-random-password', $admin->password))->toBeTrue()
        ->and(Hash::check('123123123', $admin->password))->toBeFalse()
        ->and(AiModelPrice::count())->toBeGreaterThan(0)
        ->and(Hotel::count())->toBe(0)
        ->and(Guest::withoutGlobalScope('hotel')->count())->toBe(0)
        ->and(Reservation::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('refuses to create a known-password super admin in production', function () {
    config(['seeding.demo' => false, 'seeding.super_admin.password' => null, 'seeding.super_admin.email' => 'owner@example.test']);

    expect(fn () => seedAs('production'))->toThrow(RuntimeException::class, 'SEED_SUPER_ADMIN_PASSWORD');
});

it('adds the demo hotel in production only when asked for', function () {
    config(['seeding.demo' => true, 'seeding.super_admin.password' => 'a-long-random-password', 'seeding.super_admin.email' => 'owner@example.test']);

    seedAs('production');

    expect(Hotel::where('slug', 'grand-harbor-hotel')->exists())->toBeTrue()
        ->and(Hotel::sole()->owner_id)->toBe(User::where('role', UserRole::SUPER_ADMIN)->sole()->id);
});

it('refuses to create the demo-email super admin in production', function () {
    config(['seeding.demo' => false, 'seeding.super_admin.password' => 'a-long-random-password', 'seeding.super_admin.email' => null]);

    expect(fn () => seedAs('production'))->toThrow(RuntimeException::class, 'SEED_SUPER_ADMIN_EMAIL');
});

it('can seed the platform again without failing or duplicating the super admin', function () {
    config(['seeding.demo' => false, 'seeding.super_admin.password' => 'a-long-random-password', 'seeding.super_admin.email' => 'owner@example.test']);

    seedAs('production');
    app(PlatformSeeder::class)->run();

    expect(User::where('email', 'owner@example.test')->count())->toBe(1);
});
