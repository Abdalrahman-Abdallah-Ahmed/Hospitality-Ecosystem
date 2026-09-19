<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ActivityAudience;
use Illuminate\Validation\Rule;

/**
 * Range rules for the attributes pitching reads, on top of the generic
 * schema-derived type rules. Each is optional and null means "unknown".
 *
 * daily_capacity starts at 1 on purpose: 0 would mean "full every day" and
 * silently take the activity out of every pitch.
 */
trait ValidatesActivityPitchingAttributes
{
    /**
     * @param  array<string, array<int, mixed>>  $rules  the generic rules to extend
     * @return array<string, array<int, mixed>>
     */
    protected function withPitchingAttributeRules(array $rules): array
    {
        $attributes = [
            'audience' => ['nullable', Rule::enum(ActivityAudience::class)],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'daily_capacity' => ['nullable', 'integer', 'min:1'],
        ];

        foreach ($attributes as $field => $fieldRules) {
            $rules[$field] = [...($rules[$field] ?? []), ...$fieldRules];
        }

        return $rules;
    }
}
