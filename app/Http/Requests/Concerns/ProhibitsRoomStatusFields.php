<?php

namespace App\Http\Requests\Concerns;

/**
 * The room edit no longer sets room or housekeeping status (research R2):
 * stays and the out-of-order actions set the first, tasks and the
 * housekeeping-status action the second. Old values (maintenance, blocked)
 * get the same answer.
 */
trait ProhibitsRoomStatusFields
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function withoutRoomStatusFields(array $rules): array
    {
        return [
            ...$rules,
            'status' => ['prohibited'],
            'housekeeping_status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Room status (available, occupied, out_of_order) is set by check-in and check-out, '
                .'and by POST /room/{id}/out-of-order and POST /room/{id}/return-to-service.',
            'housekeeping_status.prohibited' => 'Housekeeping status (dirty, cleaning, clean, inspected) is set by cleaning '
                .'and inspection tasks, or by PUT /room/{id}/housekeeping-status.',
        ];
    }
}
