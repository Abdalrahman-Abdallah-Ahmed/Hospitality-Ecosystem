<?php

namespace App\Http\Requests\RoomType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    public function rules(): array
    {
        $hotelId = $this->user()->hotel_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('room_types')
                    ->where('hotel_id', $hotelId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'max_occupancy' => ['required', 'integer', 'min:1'],
            'adult_capacity' => ['required', 'integer', 'min:1'],
            'child_capacity' => ['required', 'integer', 'min:0'],
            'bed_configuration' => ['nullable', 'json'],
            'amenities' => ['nullable', 'json'],
            'base_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function passedValidation(): void
    {
        $adult = $this->integer('adult_capacity');
        $child = $this->integer('child_capacity');
        $max = $this->integer('max_occupancy');

        if ($adult + $child > $max) {
            $this->validator->errors()->add(
                'max_occupancy',
                'Adult capacity plus child capacity cannot exceed maximum occupancy'
            );
        }
    }
}
