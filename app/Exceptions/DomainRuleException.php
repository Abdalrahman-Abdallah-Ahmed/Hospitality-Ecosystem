<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule refused an operation that both a controller and an AI tool
 * run through the same service: "the task category does not belong to the
 * chosen team", "a guest with this channel and external id already exists".
 *
 * It carries the HTTP status the controller always answered with, so moving
 * a rule out of a controller changes neither the message nor the status, and
 * the AI tools read the same message staff would see.
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return apiResponse($this->getMessage(), $this->status);
    }
}
