<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiAdvisorChatRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string',
        ];
    }
}
