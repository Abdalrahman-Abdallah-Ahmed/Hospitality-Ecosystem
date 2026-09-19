<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActivityPitchingAttributes;
use App\Http\Requests\Concerns\ValidatesActivityTimeframe;
use App\Http\Requests\Generic\GenericUpdateRequest;

class UpdateActivityRequest extends GenericUpdateRequest
{
    use ValidatesActivityPitchingAttributes, ValidatesActivityTimeframe;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->withPitchingAttributeRules($this->withTimeframeRules(parent::rules()));
    }
}
