<?php

namespace App\Http\Requests\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class CorrectKnowledgeDocumentTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'segments' => ['required', 'array', 'min:1'],
            'segments.*.location' => ['present', 'nullable', 'string'],
            'segments.*.text' => ['present', 'nullable', 'string', 'max:100000'],
        ];
    }
}
