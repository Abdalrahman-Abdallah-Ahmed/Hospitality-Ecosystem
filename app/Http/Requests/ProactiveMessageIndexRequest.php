<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericIndexRequest;

/**
 * The proactive message log: the generic filters, search, sort and
 * pagination (filter[trigger], filter[status], filter[reason],
 * filter[guest_id], filter[reservation_id]), plus a sent-date range.
 */
class ProactiveMessageIndexRequest extends GenericIndexRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sent_from' => ['nullable', 'date_format:Y-m-d'],
            'sent_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sent_from'],
        ];
    }
}
