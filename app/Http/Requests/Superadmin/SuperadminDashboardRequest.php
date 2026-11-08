<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * The window a dashboard section covers: optional UTC dates, the 30 days ending
 * today by default, at most 12 months.
 */
class SuperadminDashboardRequest extends FormRequest
{
    private const DEFAULT_DAYS_BEFORE_TO = 29;

    private const MAX_MONTHS = 12;

    /**
     * @var array{0: Carbon, 1: Carbon}|null
     */
    private ?array $window = null;

    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'];

        return ['from' => $date, 'to' => $date];
    }

    /**
     * Compares the resolved dates rather than using after_or_equal:from, so a
     * `from` sent alone is held to the range too. That error goes on `from`,
     * because the client sent no `to` to correct.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->window();

            if ($to->lt($from)) {
                $validator->errors()->add('to', 'The to date must be on or after the from date.');
            } elseif ($to->copy()->startOfDay()->gt($from->copy()->addMonthsNoOverflow(self::MAX_MONTHS))) {
                $validator->errors()->add($this->filled('to') ? 'to' : 'from', 'The window can be at most 12 months.');
            }
        }];
    }

    public function from(): Carbon
    {
        return $this->window()[0]->copy();
    }

    public function to(): Carbon
    {
        return $this->window()[1]->copy();
    }

    /**
     * Resolved once, so a request crossing midnight can't see two different
     * todays and end up with a 31-day window under an unusual cache key.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        if ($this->window === null) {
            $to = ($this->filled('to') ? Carbon::parse($this->input('to')) : now())->endOfDay();

            $from = $this->filled('from')
                ? Carbon::parse($this->input('from'))->startOfDay()
                : $to->copy()->subDays(self::DEFAULT_DAYS_BEFORE_TO)->startOfDay();

            $this->window = [$from, $to];
        }

        return $this->window;
    }
}
