<?php

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\Hotel;
use App\Services\ActivityAvailabilityService;
use App\Support\Activities\DayAvailability;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetActivitiesTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieve the activities offered by the current hotel, including each activity\'s category, name, description, price, and when it can be done: available_from/available_until bound the season, operating_hours lists the time slots per weekday in the hotel\'s local time (a missing weekday means closed that day), and unavailable_periods lists date ranges when it is closed. A null value means no restriction. '
            .'Pass a date (or a from/to range of at most '.ActivityAvailabilityService::AI_MAX_RANGE_DAYS.' days) to get live availability for those dates: whether each activity is open, why not, its time windows, and how many places remain. Always check a date this way before offering or booking an activity; never promise a place it did not confirm.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $range = $this->range($request);

        if (is_string($range)) {
            return $range;
        }

        $availability = app(ActivityAvailabilityService::class);

        $activities = Activity::where('hotel_id', $this->hotel->id)
            ->active()
            ->with('category')
            ->get()
            ->map(function (Activity $activity) use ($range, $availability) {
                $row = [
                    'id' => $activity->id,
                    'category' => $activity->category?->name,
                    'name' => $activity->name,
                    'description' => $activity->description,
                    'price' => $activity->price,
                    'currency' => $activity->currency,
                    'available_from' => $activity->available_from?->toDateString(),
                    'available_until' => $activity->available_until?->toDateString(),
                    'operating_hours' => $activity->operating_hours,
                    'unavailable_periods' => $activity->unavailable_periods,
                ];

                if ($range !== null) {
                    $row['availability'] = array_map(
                        fn (DayAvailability $day) => $this->day($day),
                        $availability->range($activity, $range[0], $range[1]),
                    );
                }

                return $row;
            })
            ->values();

        return json_encode($activities);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('A single date (YYYY-MM-DD, hotel local) to get live availability for.'),
            'from' => $schema->string()
                ->description('Start of a date range (YYYY-MM-DD) to get live availability for. Use with to.'),
            'to' => $schema->string()
                ->description('End of the date range (YYYY-MM-DD), inclusive, at most '.ActivityAvailabilityService::AI_MAX_RANGE_DAYS.' days after from.'),
        ];
    }

    /**
     * What the model is told about one date. The place count is left out when
     * the activity has no capacity: "unlimited" is not a number to quote.
     *
     * @return array<string, mixed>
     */
    private function day(DayAvailability $day): array
    {
        $row = [
            'date' => $day->date,
            'open' => $day->open && ! $day->past && ($day->remaining === null || $day->remaining > 0),
            'reason' => $day->past ? 'past_date' : $day->reason?->value,
            'windows' => $day->windows,
        ];

        if ($day->remaining !== null) {
            $row['remaining'] = max(0, $day->remaining);
        }

        return $row;
    }

    /**
     * The dates asked for, null when none were, or an error sentence for the
     * model when the input is unusable.
     *
     * @return array{0: string, 1: string}|string|null
     */
    private function range(Request $request): array|string|null
    {
        $from = $request->string('date')->toString() ?: $request->string('from')->toString();
        $to = $request->string('date')->toString() ?: ($request->string('to')->toString() ?: $from);

        if ($from === '') {
            return null;
        }

        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                return "Dates must be in YYYY-MM-DD format; got '{$date}'.";
            }
        }

        if ($to < $from) {
            return 'The end of the date range must not be before its start.';
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > ActivityAvailabilityService::AI_MAX_RANGE_DAYS) {
            return 'Availability can be checked for at most '.ActivityAvailabilityService::AI_MAX_RANGE_DAYS.' days at a time.';
        }

        return [$from, $to];
    }
}
