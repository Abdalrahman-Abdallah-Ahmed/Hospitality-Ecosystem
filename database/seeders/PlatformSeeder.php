<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * What every install needs, demo or not: the first super admin, who can then
 * create hotels and their admins (spec 017 FR-030). Credentials come from
 * config/seeding.php; only local and testing fall back to the well-known
 * development login.
 *
 * No factory: production installs without dev dependencies, so Faker is not
 * there. Safe to run again: an existing account with the email is kept.
 */
class PlatformSeeder extends Seeder
{
    public const DEVELOPMENT_EMAIL = 'admin@hospitality.test';

    private const DEVELOPMENT_PASSWORD = '123123123';

    private const DEVELOPMENT_PHONE = '201151793758';

    public function run(): void
    {
        $settings = config('seeding.super_admin');
        $development = self::development();
        $email = self::email();
        $password = $settings['password'] ?: ($development ? self::DEVELOPMENT_PASSWORD : null);

        if ($email === null || $password === null) {
            throw new RuntimeException('Set SEED_SUPER_ADMIN_EMAIL and SEED_SUPER_ADMIN_PASSWORD before seeding outside local and testing.');
        }

        $user = User::firstOrCreate(['email' => $email], [
            'name' => $settings['name'],
            'password' => $password,
            'role' => UserRole::SUPER_ADMIN,
            'phone_number' => $settings['phone_number'] ?: ($development ? self::DEVELOPMENT_PHONE : null),
        ]);

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    /**
     * The super admin's email: the configured one, or the development login
     * in local and testing only.
     */
    public static function email(): ?string
    {
        return config('seeding.super_admin.email') ?: (self::development() ? self::DEVELOPMENT_EMAIL : null);
    }

    private static function development(): bool
    {
        return app()->environment('local', 'testing');
    }
}
