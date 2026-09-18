<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Shape rules for an activity's timeframe columns, which the generic
 * schema-derived rules can only check as "some array" or "some date".
 *
 * Times are "H:i" in the hotel's own timezone and dates are "Y-m-d".
 * A null column means "no restriction".
 */
trait ValidatesActivityTimeframe
{
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param  array<string, array<int, mixed>>  $rules  the generic rules to extend
     * @return array<string, array<int, mixed>>
     */
    protected function withTimeframeRules(array $rules): array
    {
        $timeframe = [
            'available_from' => ['date_format:Y-m-d'],
            'available_until' => ['date_format:Y-m-d'],

            // A weekday left out, or given an empty list, is closed that day.
            'operating_hours' => ['array:'.implode(',', self::WEEKDAYS)],
            'operating_hours.*' => ['list'],
            'operating_hours.*.*' => ['array:start,end'],
            'operating_hours.*.*.start' => ['required', 'date_format:H:i'],
            'operating_hours.*.*.end' => ['required', 'date_format:H:i', 'after:operating_hours.*.*.start'],

            'unavailable_periods' => ['list'],
            'unavailable_periods.*' => ['array:start_date,end_date,reason'],
            'unavailable_periods.*.start_date' => ['required', 'date_format:Y-m-d'],
            'unavailable_periods.*.end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:unavailable_periods.*.start_date'],
            'unavailable_periods.*.reason' => ['nullable', 'string', 'max:255'],
        ];

        foreach ($timeframe as $field => $fieldRules) {
            $rules[$field] = [...($rules[$field] ?? []), ...$fieldRules];
        }

        return $rules;
    }

    /**
     * Checks that need more than one field. They only run once every field
     * is individually valid, so the values can be trusted to be well formed.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateSeasonOrder($validator);
                $this->validateNoOverlappingHours($validator);
            },
        ];
    }

    /**
     * On update only one end of the season may be sent, so the other is
     * taken from the stored activity.
     */
    private function validateSeasonOrder(Validator $validator): void
    {
        $activity = $this->routeModel();

        $from = $this->exists('available_from')
            ? $this->input('available_from')
            : $activity?->available_from?->toDateString();

        $until = $this->exists('available_until')
            ? $this->input('available_until')
            : $activity?->available_until?->toDateString();

        if ($from !== null && $until !== null && $until < $from) {
            $validator->errors()->add('available_until', 'The available until date must be on or after the available from date.');
        }
    }

    private function validateNoOverlappingHours(Validator $validator): void
    {
        foreach ((array) $this->input('operating_hours', []) as $day => $slots) {
            $slots = collect($slots)->sortBy('start')->values();

            foreach ($slots->skip(1) as $index => $slot) {
                if ($slot['start'] < $slots[$index - 1]['end']) {
                    $validator->errors()->add("operating_hours.{$day}", "The {$day} time slots must not overlap.");

                    break;
                }
            }
        }
    }
}
