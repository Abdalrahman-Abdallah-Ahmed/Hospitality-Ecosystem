<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ProhibitsRoomStatusFields;
use App\Http\Requests\Generic\GenericStoreRequest;

class StoreRoomRequest extends GenericStoreRequest
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
