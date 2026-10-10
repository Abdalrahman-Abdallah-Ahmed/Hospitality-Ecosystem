<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationStatus;
use App\Jobs\EvaluateProactiveTriggersJob;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of a recommendation's approval (SPEC-071).
 *
 * Every recommendation starts pending approval; only an approved one may be
 * offered to a guest. The API, the bulk queue and the Admin AI all decide
 * through here, so the rules and the audit trail are the same for each.
 *
 * Decisions are conditional updates, not read-then-write: two approvers, or
 * an approver and a pitch staging the same recommendation, cannot both win.
 */
class RecommendationApprovalService
{
    /** The fields that change what a guest would be offered (FR-006a). */
    public const CONTENT_FIELDS = ['activity_id', 'reason', 'reservation_id'];

    public const BULK_LIMIT = 100;

    /**
     * @throws RuntimeException when it is no longer pending approval, or was
     *                          already offered
     */
    public function approve(Recommendation $recommendation, User $by): Recommendation
    {
        $approved = $this->decide($recommendation, $by, RecommendationStatus::APPROVED, [RecommendationStatus::PENDING_APPROVAL], null);

        $this->evaluateProactive([$approved]);

        return $approved;
    }

    /**
     * Refuse it before a guest hears it. An approved recommendation that was
     * never offered may still be withdrawn this way.
     *
     * @throws RuntimeException when it is decided, offered or closed
     */
    public function reject(Recommendation $recommendation, User $by, ?string $reason = null): Recommendation
    {
        return $this->decide(
            $recommendation,
            $by,
            RecommendationStatus::REJECTED_BY_ADMIN,
            [RecommendationStatus::PENDING_APPROVAL, RecommendationStatus::APPROVED],
            trim((string) $reason) ?: null,
        );
    }

    /**
     * Approve or reject each id on its own (FR-008). An id that cannot be
     * decided is skipped and reported, never fatal to the rest. Ids outside
     * the caller's hotel scope read as not found.
     *
     * @param  list<string>  $ids
     * @return array{decided: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function decideMany(array $ids, string $action, User $by, ?string $reason = null): array
    {
        $found = Recommendation::whereIn('id', $ids)->get()->keyBy('id');
        $decided = [];
        $skipped = [];
        $approved = [];

        foreach ($ids as $id) {
            $recommendation = $found->get($id);

            if (! $recommendation) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];

                continue;
            }

            try {
                $approved[] = $action === 'approve'
                    ? $this->decide($recommendation, $by, RecommendationStatus::APPROVED, [RecommendationStatus::PENDING_APPROVAL], null)
                    : $this->reject($recommendation, $by, $reason);
                $decided[] = $id;
            } catch (RuntimeException) {
                $skipped[] = ['id' => $id, 'reason' => 'already_decided'];
            }
        }

        // Once per reservation, not once per recommendation.
        if ($action === 'approve') {
            $this->evaluateProactive($approved);
        }

        return ['decided' => $decided, 'skipped' => $skipped];
    }

    /**
     * An in-house guest may hear about a newly approved recommendation now,
     * rather than at the next sweep (SPEC-073). The job does nothing for a
     * hotel with proactive messaging off. Never fails the approval: it has
     * already been recorded.
     *
     * @param  list<Recommendation>  $approved
     */
    private function evaluateProactive(array $approved): void
    {
        collect($approved)
            ->filter(fn (Recommendation $recommendation) => $recommendation->reservation_id !== null)
            ->unique(fn (Recommendation $recommendation) => $recommendation->hotel_id.'|'.$recommendation->reservation_id)
            ->each(fn (Recommendation $recommendation) => rescue(
                fn () => EvaluateProactiveTriggersJob::forReservation($recommendation->hotel_id, $recommendation->reservation_id),
                report: true,
            ));
    }

    /**
     * Save a staff edit (FR-006a). Changing what the guest would be offered
     * sends an approved recommendation back for review; once it has been
     * offered or closed, those fields cannot change at all. Priority and
     * confidence never touch approval.
     *
     * @param  array<string, mixed>  $attributes  validated, without status or review fields
     *
     * @throws RuntimeException when content changes on an offered or closed recommendation
     */
    public function applyEdit(Recommendation $recommendation, array $attributes): Recommendation
    {
        $changed = array_values(array_filter(
            self::CONTENT_FIELDS,
            fn (string $field) => array_key_exists($field, $attributes) && (string) $attributes[$field] !== (string) $recommendation->{$field},
        ));

        if ($changed === []) {
            $recommendation->update($attributes);

            return $recommendation;
        }

        // Decided on the stored row, under its lock: a pitch staging this
        // recommendation is a conditional update on the same row, so it
        // either lands first (and the edit is refused) or finds the
        // recommendation back in review (and stages nothing).
        $reset = DB::transaction(function () use ($recommendation, $attributes) {
            $current = Recommendation::withoutGlobalScope('hotel')->whereKey($recommendation->getKey())->lockForUpdate()->first();

            if (! $current || $this->offered($current) || $current->status === RecommendationStatus::SENT || $current->status->isFinal()) {
                throw new RuntimeException('An offered or closed recommendation cannot change its activity, reason or reservation.');
            }

            $reset = $current->status === RecommendationStatus::APPROVED;
            $recommendation->setRawAttributes($current->getAttributes(), true);

            if ($reset) {
                $recommendation->forceFill([
                    'status' => RecommendationStatus::PENDING_APPROVAL,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                ]);
            }

            $recommendation->update($attributes);

            return $reset;
        });

        if ($reset) {
            EventLogger::record($recommendation, 'approval_reset', changes: [
                'status' => ['from' => RecommendationStatus::APPROVED->value, 'to' => RecommendationStatus::PENDING_APPROVAL->value],
                'fields' => $changed,
            ]);
        }

        return $recommendation;
    }

    /**
     * @param  list<RecommendationStatus>  $from
     */
    private function decide(Recommendation $recommendation, User $by, RecommendationStatus $to, array $from, ?string $reason): Recommendation
    {
        $previous = $recommendation->status;

        // Plain query: the model's own event logging would record each column
        // separately; the decision is recorded below as one named event.
        $updated = DB::transaction(fn () => Recommendation::withoutGlobalScope('hotel')
            ->whereKey($recommendation->getKey())
            ->whereIn('status', array_map(fn (RecommendationStatus $status) => $status->value, $from))
            ->whereNull('delivered_at')
            ->whereNull('pitch_decision_id')
            ->update([
                'status' => $to->value,
                'reviewed_by_user_id' => $by->getKey(),
                'reviewed_at' => now(),
                'review_reason' => $reason,
                'updated_at' => now(),
            ]));

        if ($updated !== 1) {
            $current = $recommendation->fresh()?->status?->value ?? $previous->value;
            $verb = $to === RecommendationStatus::APPROVED ? 'approved' : 'rejected';

            throw new RuntimeException("This recommendation can no longer be {$verb} (status: {$current}).");
        }

        $recommendation->refresh();

        EventLogger::record($recommendation, $to->value, changes: array_filter([
            'status' => ['from' => $previous->value, 'to' => $to->value],
            'review_reason' => $reason !== null ? ['from' => null, 'to' => $reason] : null,
        ]), reason: $reason);

        return $recommendation;
    }

    private function offered(Recommendation $recommendation): bool
    {
        return $recommendation->delivered_at !== null || $recommendation->pitch_decision_id !== null;
    }
}
