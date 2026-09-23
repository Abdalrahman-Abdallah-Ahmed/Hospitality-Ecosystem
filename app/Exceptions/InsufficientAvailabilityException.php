<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A reservation change would sell more rooms of a type than the hotel has
 * free on some night. A ValidationException, so every caller that already
 * handles line errors (the controller, the AI tool, the transaction rollback)
 * handles this one too; the API response adds the structured shortfalls the
 * frontend needs for its override prompt.
 */
class InsufficientAvailabilityException extends ValidationException
{
    /**
     * @var list<array{room_type_id: string, room_type_name: string, nights: list<array{date: string, short: int}>}>
     */
    public array $shortfalls = [];

    /**
     * @param  list<array{room_type_id: string, room_type_name: string, nights: list<array{date: string, short: int}>}>  $shortfalls
     */
    public static function for(array $shortfalls): self
    {
        $exception = static::withMessages(['rooms' => self::describe($shortfalls)]);
        $exception->shortfalls = $shortfalls;

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
            'shortfalls' => $this->shortfalls,
        ], $this->status);
    }

    /**
     * "Not enough rooms available: Deluxe is short by 1 on 2026-03-13 and by 2 on 2026-03-14."
     *
     * @param  list<array{room_type_name: string, nights: list<array{date: string, short: int}>}>  $shortfalls
     */
    private static function describe(array $shortfalls): string
    {
        $parts = array_map(function (array $shortfall) {
            $nights = array_map(fn (array $night) => "by {$night['short']} on {$night['date']}", $shortfall['nights']);

            return "{$shortfall['room_type_name']} is short ".implode(' and ', $nights);
        }, $shortfalls);

        return 'Not enough rooms available: '.implode('; ', $parts).'.';
    }
}
