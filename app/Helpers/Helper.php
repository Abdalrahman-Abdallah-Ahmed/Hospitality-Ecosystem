<?php

use App\Models\Hotel;

function apiAuth(){
    return request()->header('X-API-KEY') === config('app.api_key');
}

function apiResponse(string $message, int $code = 200, mixed $body = null)
{
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