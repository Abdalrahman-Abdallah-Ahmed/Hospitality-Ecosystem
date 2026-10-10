<?php

namespace App\Http\Requests;

use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Foundation\Http\FormRequest;

class DecideRecommendationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'ids' => ['required', 'array', 'min:1', 'max:'.RecommendationApprovalService::BULK_LIMIT],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['nullable', 'string', 'max:500', 'prohibited_unless:action,reject'],
        ];
    }
}
