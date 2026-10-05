<?php

namespace App\Http\Requests;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HousekeepingBoardRequest extends FormRequest
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
            'housekeeping_status' => ['nullable', Rule::enum(HousekeepingStatusesEnum::class)],
            'status' => ['nullable', Rule::enum(RoomStatusesEnum::class)],
            'floor' => ['nullable', 'string', 'max:50'],
            'building' => ['nullable', 'string', 'max:100'],
            'team_id' => ['nullable', 'uuid'],
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }
}
