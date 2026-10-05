<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ProhibitsRoomStatusFields;
use App\Http\Requests\Generic\GenericUpdateRequest;

class UpdateRoomRequest extends GenericUpdateRequest
{
    use ProhibitsRoomStatusFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->withoutRoomStatusFields(parent::rules());
    }
}
