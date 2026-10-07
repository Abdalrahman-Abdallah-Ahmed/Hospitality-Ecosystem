<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgeBaseCategory;
use App\Http\Requests\Knowledge\Concerns\ValidatesKnowledgeFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreKnowledgeDocumentRequest extends FormRequest
{
    use ValidatesKnowledgeFile;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => $this->knowledgeFileRules(),
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(KnowledgeBaseCategory::class)],
            'is_active' => ['sometimes', 'boolean'],
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateKnowledgeFile($validator)];
    }
}
