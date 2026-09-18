<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActivityTimeframe;
use App\Http\Requests\Generic\GenericStoreRequest;

class StoreActivityRequest extends GenericStoreRequest
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
