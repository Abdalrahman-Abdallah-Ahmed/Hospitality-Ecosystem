<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActivityPitchingAttributes;
use App\Http\Requests\Concerns\ValidatesActivityTimeframe;
use App\Http\Requests\Generic\GenericStoreRequest;

class StoreActivityRequest extends GenericStoreRequest
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
