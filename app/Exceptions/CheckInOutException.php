<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A check-in or check-out that cannot happen, with every reason at once:
 * a whole-reservation check-in lists each room that is not ready, keyed
 * `stays.{stay_id}.{reason}`, so the front desk can fix them all in one go.
 * A ValidationException, so the transaction rolls back and the AI tools
 * read the messages like any other validation failure.
 */
class CheckInOutException extends ValidationException
{
    /**
     * @param  array<string, list<string>>  $reasons
     */
    public static function for(array $reasons, ?string $summary = null): self
    {
        $exception = static::withMessages($reasons);

        if ($summary !== null) {
            $exception->message = $summary;
        }

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
