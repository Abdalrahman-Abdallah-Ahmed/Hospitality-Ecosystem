<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking a room out of order (POST), or changing why or until when (PATCH).
 * The expected end date is for staff only and cannot be in the past.
 */
class OutOfOrderRequest extends FormRequest
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
        $notPast = function (string $attribute, mixed $value, Closure $fail): void {
            $room = $this->route('room');
            $timezone = $room instanceof Room ? $room->hotel?->timezone : null;

            if ($value !== null && $value < CarbonImmutable::now($timezone ?? config('app.timezone'))->toDateString()) {
                $fail('The expected end date cannot be in the past.');
            }
        };

        if ($this->isMethod('PATCH')) {
            return [
                'reason' => ['sometimes', 'string', 'max:500', 'required_without:expected_end_date'],
                'expected_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', $notPast, 'required_without:reason'],
            ];
        }

        return [
            'reason' => ['required', 'string', 'max:500'],
            'expected_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', $notPast],
            'task_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
