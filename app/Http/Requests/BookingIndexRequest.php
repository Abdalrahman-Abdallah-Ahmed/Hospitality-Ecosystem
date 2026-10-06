<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericIndexRequest;

/**
 * The booking list and calendar: the generic filters, search, sort and
 * pagination, plus the filters a calendar by activity and date needs.
 */
class BookingIndexRequest extends GenericIndexRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'activity_id' => ['nullable', 'uuid'],
            'reservation_id' => ['nullable', 'uuid'],
            'scheduled_from' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:scheduled_from'],
            'cancellation_requested' => ['nullable', 'boolean'],
        ];
    }
}
