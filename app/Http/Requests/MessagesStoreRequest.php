<?php

namespace App\Http\Requests;

use App\Enums\MessageType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MessagesStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone_number' => 'required|string|max:255',
            'reservation_id' => 'nullable|exists:reservations,id',
            'content' => 'required|string|max:255',
            'message_type' => ['required', Rule::enum(MessageType::class)],
            'is_ai_generated' => 'boolean',
            'delivery_status' => 'string|in:pending,sent,delivered,failed',
        ];
    }
}
