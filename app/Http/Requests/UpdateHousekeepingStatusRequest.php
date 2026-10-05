<?php

namespace App\Http\Requests;

use App\Enums\HousekeepingStatusesEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual correction of a room's housekeeping status, with the reason
 * the audit records (FR-009).
 */
class UpdateHousekeepingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'housekeeping_status' => ['required', Rule::enum(HousekeepingStatusesEnum::class)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
