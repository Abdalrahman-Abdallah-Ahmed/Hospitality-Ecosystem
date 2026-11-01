<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assigning, changing or removing the physical rooms of a reservation's lines
 * (spec 020, contract §1). The rules that need the reservation, its lines and
 * other bookings live in RoomAssignmentService.
 */
class AssignRoomsRequest extends FormRequest
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
            'assignments' => ['required', 'array', 'min:1', 'max:50'],
            'assignments.*.line_id' => ['required', 'uuid', 'distinct'],
            'assignments.*.room_id' => ['present', 'nullable', 'uuid'],
            'assignments.*.upgrade' => ['sometimes', 'boolean'],
            'overbook_override' => ['sometimes', 'boolean'],
        ];
    }
}
