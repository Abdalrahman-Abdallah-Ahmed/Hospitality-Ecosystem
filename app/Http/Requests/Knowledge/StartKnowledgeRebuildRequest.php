<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgeRebuildScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartKnowledgeRebuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(KnowledgeRebuildScope::class)],
            'hotel_id' => ['required_if:scope,hotel', 'prohibited_unless:scope,hotel', 'nullable', 'uuid', 'exists:hotels,id'],
        ];
    }
}
