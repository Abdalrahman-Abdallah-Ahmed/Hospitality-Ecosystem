<?php

namespace App\Http\Resources;

use App\Enums\AiActivityCategory;
use App\Models\Guest;
use App\Support\Audit\HistorySubjects;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the AI activity list (SPEC-083). Provider cost never appears:
 * hotel users don't see what the AI cost, and super admins read cost through
 * /api/superadmin/ai-cost (spec 028).
 */
class AiActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isRun = $this->category === AiActivityCategory::AI_RUN;
        $slug = HistorySubjects::slugFor($this->target_type);

        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'agent' => $this->agent->value,
            'tool' => $this->tool,
            'action' => $this->action,
            'result' => $this->result->value,
            'reason' => $this->reason,
            'target' => $this->target_id === null ? null : [
                'type' => $slug,
                'id' => $this->target_id,
                'history_url' => $slug !== null && HistorySubjects::hasHistory($slug) ? "/api/history/{$slug}/{$this->target_id}" : null,
            ],
            'changes_count' => $this->changes_count,
            'conversation_id' => $this->conversation_id,
            'ai_run_id' => $this->ai_run_id,
            'acted_for' => $this->actedFor($this->resource),
            $this->mergeWhen($isRun, fn () => [
                'trigger_kind' => $this->trigger_kind?->value,
                'usage' => $this->usage,
            ]),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, id: string, name: ?string}|null
     */
    private function actedFor(mixed $activity): ?array
    {
        if ($activity->acted_for_id === null) {
            return null;
        }

        $person = $activity->actedFor;
        $isGuest = $activity->acted_for_type === (new Guest)->getMorphClass();

        return [
            'type' => $isGuest ? 'guest' : 'user',
            'id' => $activity->acted_for_id,
            'name' => $person ? ($isGuest ? trim($person->first_name.' '.$person->last_name) : $person->name) : null,
        ];
    }
}
