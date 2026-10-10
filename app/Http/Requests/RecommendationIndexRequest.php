<?php

namespace App\Http\Requests;

use App\Enums\RecommendationSource;
use App\Enums\RecommendationStatus;
use App\Http\Requests\Generic\GenericIndexRequest;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The recommendation list and approval queue (SPEC-071 FR-009): the generic
 * filters, search, sort and pagination, plus the filters a reviewer needs.
 * `sort` also accepts `arrival_date`, the reservation's arrival.
 */
class RecommendationIndexRequest extends GenericIndexRequest
{
    public const ARRIVAL_SORT = 'arrival_date';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // One status, or several comma-separated.
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                foreach (explode(',', (string) $value) as $status) {
                    if (! RecommendationStatus::tryFrom(trim($status))) {
                        $fail("Unknown status [{$status}].");
                    }
                }
            }],
            'source' => ['nullable', Rule::enum(RecommendationSource::class)],
            'guest_id' => ['nullable', 'uuid'],
            'reservation_id' => ['nullable', 'uuid'],
            'activity_id' => ['nullable', 'uuid'],
            'arrival_from' => ['nullable', 'date_format:Y-m-d'],
            'arrival_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:arrival_from'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        return [...parent::availableColumns(), self::ARRIVAL_SORT];
    }

    /**
     * `arrival_date` sorts only; it is not a column to filter on.
     *
     * @return array<int, string>
     */
    protected function filterColumns(): array
    {
        return parent::availableColumns();
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        $status = $this->string('status')->toString();

        return $status === '' ? [] : array_map('trim', explode(',', $status));
    }
}
