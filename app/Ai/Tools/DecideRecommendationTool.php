<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Approves or rejects one recommendation the admin named (SPEC-071 FR-012).
 * Approving decides what the hotel tells a guest, so the admin confirms each
 * decision first. There is no list argument: approving in bulk happens in
 * the approval queue, never through the AI.
 */
class DecideRecommendationTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Approve or reject ONE activity recommendation the admin named, by its id from the review list. The system '
            .'asks the admin to confirm first. Never use it for several at once: point bulk requests to the approval queue.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return true;
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $recommendation = $this->recommendation($request);
        $approve = $request->string('action')->toString() === 'approve';

        if (! $recommendation) {
            return $locale === 'ar'
                ? 'توصية غير موجودة في هذا الفندق — لن يتغير شيء.'
                : 'A recommendation not found in this hotel; nothing will change.';
        }

        $guest = $this->guestName($recommendation->reservation?->guest);
        $activity = $recommendation->activity?->name ?? '?';
        $rooms = $recommendation->reservation?->reservationRooms->map(fn ($line) => $line->room?->room_number)->filter()->implode(', ');
        $room = $rooms ? ($locale === 'ar' ? " (غرفة {$rooms})" : " (room {$rooms})") : '';

        return match (true) {
            $locale === 'ar' && $approve => "اعتماد توصية \"{$activity}\" للضيف {$guest}{$room}.",
            $locale === 'ar' => "رفض توصية \"{$activity}\" للضيف {$guest}{$room}.",
            $approve => "Approve \"{$activity}\" for {$guest}{$room}.",
            default => "Reject \"{$activity}\" for {$guest}{$room}.",
        };
    }

    public function handle(Request $request): Stringable|string
    {
        $recommendation = $this->recommendation($request);

        if (! $recommendation) {
            return 'This hotel has no recommendation with that id.';
        }

        $action = $request->string('action')->toString();

        if (! in_array($action, ['approve', 'reject'], true)) {
            return $this->notDone('Say approve or reject');
        }

        $approvals = app(RecommendationApprovalService::class);
        $result = $this->attempt(fn () => $action === 'approve'
            ? $approvals->approve($recommendation, $this->user)
            : $approvals->reject($recommendation, $this->user, trim($request->string('reason')->toString()) ?: null));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['recommendation_id' => $result->id], ['status' => $result->status->value]);
    }

    private function recommendation(Request $request): ?Recommendation
    {
        return $this->findOwn(Recommendation::class, $request->string('recommendation_id')->toString())
            ?->load(['reservation.guest', 'reservation.reservationRooms.room', 'activity']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendation_id' => $schema->string()->description('The id from the review list.')->required(),
            'action' => $schema->string()->enum(['approve', 'reject'])->required(),
            'reason' => $schema->string()->description('Why it is rejected, if the admin said (up to 500 characters).'),
        ];
    }
}
