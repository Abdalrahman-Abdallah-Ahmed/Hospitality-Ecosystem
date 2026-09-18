<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActivityTimeframe;
use App\Http\Requests\Generic\GenericUpdateRequest;

class UpdateActivityRequest extends GenericUpdateRequest
{
    use ValidatesActivityTimeframe;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->withTimeframeRules(parent::rules());
    }
}
