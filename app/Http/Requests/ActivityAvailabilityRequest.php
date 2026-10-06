<?php

namespace App\Http\Requests;

use App\Services\ActivityAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An activity availability lookup: one date, or a range of at most
 * ActivityAvailabilityService::MAX_RANGE_DAYS days. Past dates are allowed so
 * the booking calendar can show history; each day says whether it is past.
 */
class ActivityAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->to())) + 1;

                if ($days > ActivityAvailabilityService::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'Availability can be checked for at most '.ActivityAvailabilityService::MAX_RANGE_DAYS.' days at a time.');
                }
            },
        ];
    }

    /**
     * The last date asked for: the first one when no range was given.
     */
    public function to(): string
    {
        return $this->input('to') ?: $this->input('from');
    }
}
