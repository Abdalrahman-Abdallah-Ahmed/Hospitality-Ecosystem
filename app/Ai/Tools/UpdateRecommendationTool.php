<?php

namespace App\Ai\Tools;

use App\Enums\AttributionMethod;
use App\Enums\OutcomeType;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\RecommendationOutcomeService;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Conversational capture — the primary way an outcome is recorded.
 *
 * The agent holds the exchange in which the guest accepted or refused, so the
 * outcome does not have to be reconstructed from anywhere else. It is also the
 * only place a refusal exists at all: a guest who says no produces no booking,
 * no payment, and no downstream record of any kind. "Offered and refused" is
 * the most actionable signal the system can collect, and nothing but the
 * conversation contains it.
 */
class UpdateRecommendationTool implements Tool
{
    public function __construct(
        private readonly ?Reservation $reservation,
    ) {}

    public function description(): Stringable|string
    {
        return "Record the guest's reaction to a recommendation you showed them — did they agree, refuse, or not decide? Include the words they actually used and how sure you are of your reading. Only affects the guest's own recommendations.";
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'No reservation found for this guest.';
        }

        $recommendation = Recommendation::where('reservation_id', $this->reservation->id)
            ->find($request->string('recommendation_id')->toString());

        if (! $recommendation) {
            return 'No such recommendation for this guest. Only use recommendation ids returned by the get-recommendations tool.';
        }

        $action = $request->string('action')->toString() ?: null;
        $guestConfidence = $request->filled('guest_confidence') ? $request->float('guest_confidence') : null;

        if (! $action && $guestConfidence === null) {
            return 'Nothing to update — provide an action and/or a guest_confidence.';
        }

        $updates = match ($action) {
            'accepted' => ['status' => RecommendationStatus::ACCEPTED, 'accepted_at' => now()],
            'rejected' => ['status' => RecommendationStatus::REJECTED, 'rejected_at' => now()],
            'dismissed' => ['status' => RecommendationStatus::IGNORED, 'dismissed_at' => now()],
            default => [],
        };

        if ($guestConfidence !== null) {
            $updates['guest_confidence'] = $guestConfidence;
        }

        $outcome = $this->outcomeFor($action);

        EventLogger::asAiAgent(function () use ($recommendation, $updates, $outcome, $request, $guestConfidence) {
            $recommendation->update($updates);

            if (! $outcome) {
                return;
            }

            app(RecommendationOutcomeService::class)->record(
                $recommendation,
                $outcome,
                AttributionMethod::CONVERSATIONAL,
                attributes: [
                    'channel' => 'whatsapp',
                    'decline_reason' => trim($request->string('decline_reason')->toString()) ?: null,
                    // The raw statement is what settles a later dispute about
                    // whether this classification was right.
                    'evidence_quote' => trim($request->string('evidence_quote')->toString()) ?: null,
                    'confidence' => $request->filled('confidence')
                        ? $request->float('confidence')
                        : $guestConfidence,
                ],
            );
        });

        return "Recommendation updated (id: {$recommendation->id}).";
    }

    /**
     * A guest who merely showed no interest has still been *offered* the
     * activity — that is DELIVERED, not a refusal. Only an explicit no is
     * DECLINED, because the two mean different things to whoever has to act
     * on the number.
     */
    private function outcomeFor(?string $action): ?OutcomeType
    {
        return match ($action) {
            'accepted' => OutcomeType::ACCEPTED,
            'rejected' => OutcomeType::DECLINED,
            'dismissed' => OutcomeType::DELIVERED,
            default => null,
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendation_id' => $schema->string()
                ->description('The id of the recommendation to update, from the get-recommendations tool.')
                ->required(),
            'action' => $schema->string()
                ->enum(['accepted', 'rejected', 'dismissed'])
                ->description('accepted: the guest wants it. rejected: they explicitly said no. dismissed: they showed no interest either way.'),
            'guest_confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->description('How interested the guest seemed, from 0 (not interested) to 1 (very interested).'),
            'confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->description('How sure YOU are that your reading of their response is correct, from 0 to 1. Be honest: a polite "we\'ll see" or "maybe later" is not agreement, and a low value here is recorded as "offered, undecided" rather than as a decision. Guessing high inflates every number built on this.'),
            'decline_reason' => $schema->string()
                ->description('Why they said no, in a few words (e.g. "price", "timing", "already booked elsewhere"). Only when the action is rejected.'),
            'evidence_quote' => $schema->string()
                ->description("The guest's own words that led to your classification, quoted as they wrote them."),
        ];
    }
}
