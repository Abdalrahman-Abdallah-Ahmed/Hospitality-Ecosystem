<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The window of arrivals that still need a room (spec 020 FR-018): default
 * the hotel's today through 7 days ahead, at most 31 days. Its own request,
 * not GenericIndexRequest: the list joins lines to their reservation, guest
 * and room type, so it sorts by those, not by one table's columns.
 */
class UnassignedArrivalsRequest extends FormRequest
{
    public const MAX_DAYS = 31;

    public const SORTS = ['arrival_date', 'reservation_code', 'room_type'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = [...self::SORTS, ...array_map(fn (string $sort) => "-{$sort}", self::SORTS)];

        return [
            // Both ends or neither: one end alone would leave the other to a
            // default the window checks below never see.
            'from' => ['required_with:to', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'nullable', Rule::in($sorts)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to) || $validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);

            if ($days < 0) {
                $validator->errors()->add('to', 'The end of the window must not be before its start.');
            } elseif ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'The window can be at most '.self::MAX_DAYS.' days.');
            }
        });
    }
}
