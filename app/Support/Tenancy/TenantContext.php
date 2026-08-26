<?php

namespace App\Support\Tenancy;

/**
 * Per-request tenant state. Populated once by ResolveTenant (or explicitly,
 * inside jobs/webhooks that have no HTTP request of their own) and read by
 * BelongsToHotel's global scope on every tenant-owned model query.
 *
 * `hotelIds()` distinguishes "unrestricted" (null — e.g. a super admin, or
 * no context set at all) from "restricted to these hotels, which may be
 * none" ([] — a user with no accessible hotel yet). Collapsing the two would
 * mean a user with zero hotels sees every hotel's data instead of none, so
 * BelongsToHotel checks `!== null`, not truthiness.
 */
class TenantContext
{
    protected static ?array $hotelIds = null;

    protected static ?string $currentHotelId = null;

    public static function setHotelIds(?array $ids): void
    {
        static::$hotelIds = $ids;
    }

    public static function hotelIds(): ?array
    {
        return static::$hotelIds;
    }

    public static function setCurrentHotelId(?string $id): void
    {
        static::$currentHotelId = $id;
    }

    public static function currentHotelId(): ?string
    {
        return static::$currentHotelId;
    }

    public static function reset(): void
    {
        static::$hotelIds = null;
        static::$currentHotelId = null;
    }

    /**
     * Run a callback with tenant scoping fully lifted, restoring the prior
     * context afterward even if the callback throws. Escape hatch for
     * queue jobs, webhooks, and admin/reporting code — use deliberately,
     * never to make a failing isolation test pass.
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previousHotelIds = static::$hotelIds;
        $previousCurrentHotelId = static::$currentHotelId;

        static::$hotelIds = null;

        try {
            return $callback();
        } finally {
            static::$hotelIds = $previousHotelIds;
            static::$currentHotelId = $previousCurrentHotelId;
        }
    }

    /**
     * Run a callback scoped to exactly one hotel, restoring the prior
     * context afterward even if the callback throws. For jobs/webhooks
     * that resolve their own hotel outside of any HTTP request.
     */
    public static function runForHotel(?string $hotelId, callable $callback): mixed
    {
        $previousHotelIds = static::$hotelIds;
        $previousCurrentHotelId = static::$currentHotelId;

        static::$hotelIds = $hotelId ? [$hotelId] : [];
        static::$currentHotelId = $hotelId;

        try {
            return $callback();
        } finally {
            static::$hotelIds = $previousHotelIds;
            static::$currentHotelId = $previousCurrentHotelId;
        }
    }
}
