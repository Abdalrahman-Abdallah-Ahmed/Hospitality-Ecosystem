<?php

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
