<?php

namespace App\Http\Requests\Generic;

use App\Http\Requests\Concerns\ValidatesExportFormat;

/**
 * Export of a list served by GenericIndexRequest: the same filters, search
 * and sort, plus the format. `page` and `per_page` are accepted and ignored,
 * since an export covers every page.
 */
class GenericExportRequest extends GenericIndexRequest
{
    use ValidatesExportFormat;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->exportRules()];
    }
}
