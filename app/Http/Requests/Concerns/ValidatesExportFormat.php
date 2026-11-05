<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ExportFormat;
use Illuminate\Validation\Rule;

/**
 * What a list export adds to its list's query string (spec 021): the file
 * format, CSV unless asked otherwise, and the hotel a super admin exports.
 */
trait ValidatesExportFormat
{
    /**
     * The export permission is checked here, before validation, so a user
     * without it gets 403 and never learns the table's filter and sort
     * columns from 422 messages.
     */
    public function authorize(): bool
    {
        return apiAuth() && (bool) $this->user()?->can('export', $this->modelClass());
    }

    /**
     * @return array<string, mixed>
     */
    protected function exportRules(): array
    {
        return [
            'format' => ['nullable', Rule::enum(ExportFormat::class)],
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Not format(): Request already has one, for the response's content type.
     */
    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from($this->validated('format') ?? ExportFormat::CSV->value);
    }
}
