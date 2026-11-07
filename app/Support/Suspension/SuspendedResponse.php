<?php

namespace App\Support\Suspension;

use App\Enums\SuspensionScope;
use App\Models\Hotel;
use App\Support\Observability\TagsSentryScope;
use Illuminate\Http\JsonResponse;

/**
 * The refusal login, the tenant routes and a hotel's own routes all return, so
 * a client can recognize it from body.reason whichever one answered.
 */
class SuspendedResponse
{
    public static function make(SuspensionScope $scope): JsonResponse
    {
        TagsSentryScope::tag(['suspended' => 'true']);

        return apiResponse(__('suspension.'.$scope->value), 403, [
            'reason' => 'suspended',
            'scope' => $scope->value,
        ]);
    }

    public static function forHotel(Hotel $hotel): JsonResponse
    {
        return self::make(self::scopeOf($hotel));
    }

    public static function scopeOf(Hotel $hotel): SuspensionScope
    {
        return $hotel->accountIsSuspended() ? SuspensionScope::ACCOUNT : SuspensionScope::HOTEL;
    }
}
