<?php

namespace App\Http\Requests\Superadmin\Concerns;

use Illuminate\Validation\Rule;

/**
 * A virtual filter[suspended] for a super admin list, which the controller
 * applies. Filterable ignores keys that aren't columns, so it needs no
 * stripping, and it stays unusable for sort.
 */
trait FiltersBySuspension
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Not Laravel's `boolean`, which refuses "true": a query string only
            // has strings.
            'filter.suspended' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function filterColumns(): array
    {
        return [...parent::filterColumns(), 'suspended'];
    }

    /**
     * Null means no filter was asked for.
     */
    public function suspendedFilter(): ?bool
    {
        $value = data_get($this->input('filter'), 'suspended');

        return $value === null || $value === '' ? null : in_array((string) $value, ['true', '1'], true);
    }
}
