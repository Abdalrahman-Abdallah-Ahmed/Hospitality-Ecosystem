<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A check-in, for one room or a whole reservation. `room_id` (one room) or
 * `rooms` (whole reservation) name a room for a line that has none; the rules
 * that need the hotel and the reservation live in StayLifecycleService.
 */
class CheckInRequest extends FormRequest
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
            'room_id' => ['sometimes', 'nullable', 'uuid'],
            'rooms' => ['sometimes', 'array'],
            'rooms.*.stay_id' => ['required', 'uuid', 'distinct'],
            'rooms.*.room_id' => ['required', 'uuid'],
            'checked_in_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * `rooms` as the service takes it: room id by stay id.
     *
     * @return array<string, string>
     */
    public function roomsByStayId(): array
    {
        return collect($this->validated('rooms', []))
            ->mapWithKeys(fn (array $item) => [strtolower($item['stay_id']) => strtolower($item['room_id'])])
            ->all();
    }
}
