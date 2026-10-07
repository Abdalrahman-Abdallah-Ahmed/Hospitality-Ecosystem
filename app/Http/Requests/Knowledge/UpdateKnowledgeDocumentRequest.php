<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgeBaseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKnowledgeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', Rule::enum(KnowledgeBaseCategory::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
