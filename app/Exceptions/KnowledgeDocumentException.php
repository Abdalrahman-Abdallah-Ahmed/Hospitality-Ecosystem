<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A knowledge document action that cannot happen as asked: a correction whose
 * locations do not match the document, a correction before there is any text.
 */
class KnowledgeDocumentException extends ValidationException
{
    public static function because(string $field, string $message): self
    {
        $exception = static::withMessages([$field => [$message]]);
        $exception->message = $message;

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->status,
            'errors' => $this->errors(),
        ], $this->status);
    }
}
