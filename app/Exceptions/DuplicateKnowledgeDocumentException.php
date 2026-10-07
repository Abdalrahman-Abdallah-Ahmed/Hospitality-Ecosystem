<?php

namespace App\Exceptions;

use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The upload is byte-for-byte identical to a live document in the same scope
 * (the same hotel, or the global knowledge). Answers 409 with the existing
 * document, so staff can go to it instead.
 */
class DuplicateKnowledgeDocumentException extends RuntimeException
{
    public function __construct(public readonly KnowledgeDocument $existing)
    {
        parent::__construct(__('knowledge.duplicate', ['title' => $existing->title]));
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return apiResponse($this->getMessage(), 409, [
            'existing' => ['id' => $this->existing->id, 'title' => $this->existing->title],
        ]);
    }
}
