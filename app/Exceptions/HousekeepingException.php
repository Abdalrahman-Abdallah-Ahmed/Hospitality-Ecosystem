<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A housekeeping or maintenance action that cannot happen: a second open
 * cleaning task for a room, out of order with a guest in the room, an
 * inspection completed outside the inspection action. A ValidationException,
 * so the surrounding transaction rolls back and the AI tools read the message
 * like any other validation failure.
 */
class HousekeepingException extends ValidationException
{
    public static function because(string $field, string $message): self
    {
        $exception = static::withMessages([$field => [$message]]);
        $exception->message = $message;

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->status,
            'errors' => $this->errors(),
        ], $this->status);
    }
}
