<?php

namespace App\Ai\Tools;

use App\Enums\AiAgentName;
use App\Support\Ai\AiActionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Wraps a write tool of any agent other than the Admin AI (which has
 * GuardedTool), so every call is one transaction, audited as the AI acting
 * for $actedFor, and leaves one AI activity entry — succeeded, refused or
 * failed (SPEC-083). The conversation comes from the current AiRun.
 *
 * The model sees the inner tool's own name, description and schema.
 */
class AuditedTool implements Tool
{
    public function __construct(
        private readonly Tool $inner,
        private readonly AiAgentName $agent,
        private readonly ?Model $actedFor = null,
    ) {}

    public function name(): string
    {
        return class_basename($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return AiActionRecorder::around(
            $this->agent,
            $this->inner::class,
            $this->actedFor,
            $request->toolCallId(),
            null,
            fn () => $this->inner->handle($request),
        );
    }

    public function inner(): Tool
    {
        return $this->inner;
    }
}
