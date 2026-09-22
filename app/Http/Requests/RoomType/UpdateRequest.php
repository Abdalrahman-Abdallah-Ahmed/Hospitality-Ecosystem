<?php

namespace App\Http\Requests\RoomType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    public function rules(): array
    {
        $hotelId = $this->user()->hotel_id;
        $roomTypeId = $this->route('id');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('room_types')
                    ->where('hotel_id', $hotelId)
                    ->whereNull('deleted_at')
                    ->ignore($roomTypeId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'max_occupancy' => ['sometimes', 'integer', 'min:1'],
            'adult_capacity' => ['sometimes', 'integer', 'min:1'],
            'child_capacity' => ['sometimes', 'integer', 'min:0'],
            'bed_configuration' => ['sometimes', 'nullable', 'json'],
            'amenities' => ['sometimes', 'nullable', 'json'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->has('adult_capacity') || $this->has('child_capacity') || $this->has('max_occupancy')) {
            $roomType = $this->route('roomType');
            $adult = $this->integer('adult_capacity', $roomType->adult_capacity);
            $child = $this->integer('child_capacity', $roomType->child_capacity);
            $max = $this->integer('max_occupancy', $roomType->max_occupancy);

            if ($adult + $child > $max) {
                $this->validator->errors()->add(
                    'max_occupancy',
                    'Adult capacity plus child capacity cannot exceed maximum occupancy'
                );
            }
        }
    }
}
