<?php

namespace App\Exceptions;

use App\Enums\ActivityUnavailableReason;
use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An activity cannot take this booking: closed, out of season, outside its
 * hours, or out of places. A ValidationException, so every caller that
 * already handles validation errors handles this one too; the API response
 * adds the structured `unavailable` details the frontend needs for its
 * override prompt, and the AI tools read the same details to explain it.
 */
class ActivityUnavailableException extends ValidationException
{
    public ActivityUnavailableReason $reason;

    /**
     * @var array{reason: string, date: string, windows: list<array{start: string, end: string}>, capacity: ?int, booked: ?int, remaining: ?int, closure_reason: ?string, overridable: bool}
     */
    public array $unavailable = [];

    /**
     * @param  array{windows?: list<array{start: string, end: string}>, capacity?: ?int, booked?: ?int, remaining?: ?int, closure_reason?: ?string, time?: ?string, pax?: int}  $details
     */
    public static function for(Activity $activity, ActivityUnavailableReason $reason, string $date, array $details = []): self
    {
        $exception = static::withMessages(['scheduled_for' => self::describe($activity, $reason, $date, $details)]);
        $exception->reason = $reason;
        $exception->unavailable = [
            'reason' => $reason->value,
            'date' => $date,
            'windows' => $details['windows'] ?? [],
            'capacity' => $details['capacity'] ?? null,
            'booked' => $details['booked'] ?? null,
            'remaining' => $details['remaining'] ?? null,
            'closure_reason' => $details['closure_reason'] ?? null,
            'overridable' => $reason->overridable(),
        ];

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
            'unavailable' => $this->unavailable,
        ], $this->status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function describe(Activity $activity, ActivityUnavailableReason $reason, string $date, array $details): string
    {
        $name = $activity->name;
        $windows = self::windows($details['windows'] ?? []);

        return match ($reason) {
            ActivityUnavailableReason::INACTIVE => "{$name} is not currently offered.",
            ActivityUnavailableReason::PAST_DATE => "{$name} cannot be booked for {$date}, which is in the past.",
            ActivityUnavailableReason::OUT_OF_SEASON => "{$name} is out of season on {$date}.",
            ActivityUnavailableReason::CLOSURE_PERIOD => "{$name} is closed on {$date}".(($details['closure_reason'] ?? null) ? " ({$details['closure_reason']})." : '.'),
            ActivityUnavailableReason::CLOSED_WEEKDAY => "{$name} is closed on ".CarbonImmutable::parse($date)->format('l').'s.',
            ActivityUnavailableReason::TIME_REQUIRED => "{$name} needs a start time on {$date}: it runs {$windows}.",
            ActivityUnavailableReason::OUTSIDE_OPENING_HOURS => "{$name} runs {$windows} on {$date}; ".($details['time'] ?? 'that time').' is outside those hours.',
            ActivityUnavailableReason::PARTY_EXCEEDS_CAPACITY => "{$name} takes at most {$details['capacity']} people a day, so a party of {$details['pax']} cannot fit.",
            ActivityUnavailableReason::FULLY_BOOKED => "{$name} is fully booked on {$date} (".max(0, (int) $details['remaining'])." of {$details['capacity']} places left).",
            ActivityUnavailableReason::OUTSIDE_RESERVATION => "{$date} is outside the guest's reservation.",
        };
    }

    /**
     * @param  list<array{start: string, end: string}>  $windows
     */
    private static function windows(array $windows): string
    {
        return implode(' and ', array_map(fn (array $w) => "{$w['start']}–{$w['end']}", $windows)) ?: 'no hours that day';
    }
}
