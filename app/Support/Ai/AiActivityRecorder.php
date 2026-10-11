<?php

namespace App\Support\Ai;

use App\Enums\AiActivityCategory;
use App\Enums\AiActivityResult;
use App\Enums\AiAgentName;
use App\Enums\AiTriggerKind;
use App\Models\AiActivity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The single writer of ai_activities (SPEC-083). Takes identifiers and short
 * system-written reasons only: no conversation text, prompt, model output or
 * memory content ever reaches it (FR-024).
 */
class AiActivityRecorder
{
    public static function dataChange(
        AiAgentName|string $agent,
        string $tool,
        AiActivityResult $result,
        string $action,
        ?Model $target = null,
        int $changesCount = 0,
        ?Model $actedFor = null,
        ?string $conversationId = null,
        ?string $reason = null,
    ): AiActivity {
        return self::write([
            'category' => AiActivityCategory::DATA_CHANGE,
            'agent' => self::agent($agent),
            'tool' => self::toolName($tool),
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'changes_count' => $changesCount,
            'conversation_id' => $conversationId ?? AiRun::conversationId(),
        ], $target, $actedFor);
    }

    public static function outreach(
        string $action,
        Model $target,
        ?Model $actedFor,
        string $tool,
        ?string $reason = null,
        ?string $conversationId = null,
    ): AiActivity {
        return self::write([
            'category' => AiActivityCategory::GUEST_OUTREACH,
            'agent' => AiAgentName::CONCIERGE,
            'tool' => $tool,
            'action' => $action,
            'result' => AiActivityResult::SUCCEEDED,
            'reason' => $reason,
            'conversation_id' => $conversationId ?? AiRun::conversationId(),
        ], $target, $actedFor);
    }

    /**
     * @param  array{requests: int, input_tokens: int, output_tokens: int, cached_tokens: int}|null  $usage
     */
    public static function run(
        string $id,
        AiAgentName $agent,
        AiTriggerKind $kind,
        ?string $hotelId,
        ?Model $actedFor,
        ?string $conversationId,
        AiActivityResult $result,
        ?string $reason,
        ?array $usage,
    ): AiActivity {
        return self::write([
            'id' => $id,
            'ai_run_id' => $id,
            'hotel_id' => $hotelId,
            'category' => AiActivityCategory::AI_RUN,
            'agent' => $agent,
            'action' => 'run.completed',
            'result' => $result,
            'reason' => $reason,
            'conversation_id' => $conversationId,
            'trigger_kind' => $kind,
            'usage' => $usage,
        ], null, $actedFor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function write(array $attributes, ?Model $target, ?Model $actedFor): AiActivity
    {
        $attributes['reason'] = isset($attributes['reason']) ? Str::limit($attributes['reason'], 200, '') : null;
        $attributes['ai_run_id'] ??= AiRun::id();
        $attributes['hotel_id'] ??= $target?->getAttribute('hotel_id') ?? AiRun::hotelId() ?? TenantContext::currentHotelId();

        $activity = (new AiActivity)->forceFill([
            ...$attributes,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'acted_for_type' => $actedFor?->getMorphClass(),
            'acted_for_id' => $actedFor?->getKey(),
            'occurred_at' => now(),
        ]);
        $activity->save();

        return $activity;
    }

    private static function agent(AiAgentName|string $agent): AiAgentName
    {
        return $agent instanceof AiAgentName ? $agent : (AiAgentName::tryFrom($agent) ?? AiAgentName::UNATTRIBUTED);
    }

    /**
     * Anonymous classes (tests) have a name no database column can hold.
     */
    public static function toolName(string $tool): string
    {
        return str_contains($tool, '@anonymous') ? 'anonymous' : class_basename($tool);
    }
}
