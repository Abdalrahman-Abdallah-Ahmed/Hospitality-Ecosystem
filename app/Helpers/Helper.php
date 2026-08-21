<?php

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

function apiAuth(){
    return request()->header('X-API-KEY') === config('app.api_key');
}


function apiResponse(string $message, int $code = 200, mixed $body = null)
{
    if ($body instanceof ResourceCollection) {
        $body = $body->response()->getData(true);
    } elseif ($body instanceof JsonResource) {
        $body = $body->resolve();
    }

    return response()->json([
        'message' => $message,
        'code' => $code,
        'body' => $body,
    ], $code);
}

function unsetAttributes(array $attributes, array $keysToUnset): array
{
    foreach ($keysToUnset as $key) {
        unset($attributes[$key]);
    }

    return $attributes;
}


function invalidRelation(Hotel $hotel, array $relations): ?string
{
    foreach ($relations as $relation => $value) {
        if ($value === null) {
            continue;
        }

        $allowedIds = $hotel->{$relation}()->pluck('id')->toArray();

        if (! in_array($value, $allowedIds, true)) {
            return $relation;
        }
    }
    return null;
}

/**
 * The hotel a create endpoint should attach a new record to: a scoped
 * admin always gets their own hotel (whatever hotel_id they submitted is
 * ignored), while a super admin has no hotel of their own and must
 * explicitly choose one via the submitted hotel_id.
 */
function resolveHotel(User $user, ?string $requestedHotelId = null): ?Hotel
{
    if ($user->isSuperAdmin()) {
        return $requestedHotelId ? Hotel::find($requestedHotelId) : null;
    }

    return $user->hotel;
}
