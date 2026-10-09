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
            'message' => 'required_without:decision|nullable|string|max:4000',
            'conversation_id' => 'required_with:decision|nullable|string',
            // The Confirm / Cancel answer to actions waiting for confirmation
            // (FR-017). `pending_ids`, when sent, must be exactly what waits.
            'decision' => 'nullable|in:confirm,decline',
            'pending_ids' => 'nullable|array',
            'pending_ids.*' => 'string',
        ];
    }
}
