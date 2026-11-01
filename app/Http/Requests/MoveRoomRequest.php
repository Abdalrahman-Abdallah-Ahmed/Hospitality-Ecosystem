<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving a guest in the house to another room (spec 020, contract §2). The
 * reason is required: a move is a decision someone answers for. The rules
 * that need the stay and the rooms live in RoomAssignmentService.
 */
class MoveRoomRequest extends FormRequest
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
            'room_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'upgrade' => ['sometimes', 'boolean'],
            'overbook_override' => ['sometimes', 'boolean'],
        ];
    }
}
