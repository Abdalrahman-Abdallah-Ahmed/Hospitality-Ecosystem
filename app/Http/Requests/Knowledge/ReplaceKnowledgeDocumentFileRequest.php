<?php

namespace App\Http\Requests\Knowledge;

use App\Http\Requests\Knowledge\Concerns\ValidatesKnowledgeFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReplaceKnowledgeDocumentFileRequest extends FormRequest
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
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateKnowledgeFile($validator)];
    }
}
