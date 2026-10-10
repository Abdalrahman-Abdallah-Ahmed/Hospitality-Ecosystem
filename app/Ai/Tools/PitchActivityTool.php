<?php

namespace App\Ai\Tools;

use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\Pitching\PitchEligibilityService;
use App\Support\Pitching\GateReport;
use App\Support\Pitching\PitchTurn;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Offers the guest the one approved recommendation this turn may offer
 * (WP-17, SPEC-071). The Concierge decides only *whether* now is a natural
 * moment, and proves it by quoting the guest's own words; *what* to offer was
 * decided in code, from RecommendationAgent's stored order, before the agent
 * ran.
 *
 * Only given to the Concierge when the turn may pitch. It stages the pitch;
 * delivery is stamped only once WhatsApp has accepted the reply
 * (PitchCoordinator::delivered()).
 */
class PitchActivityTool implements Tool
{
    public function __construct(
        private readonly PitchTurn $turn,
        private readonly Stay $stay,
    ) {}

    public function description(): Stringable|string
    {
        $offer = $this->turn->offer();

        return "Offer the guest {$offer?->name} — the hotel's one approved suggestion for this moment. Call it only after answering what the guest asked, and only if it fits naturally. Pass the words from the guest's current message that make a suggestion welcome.";
    }

    public function handle(Request $request): Stringable|string
    {
        $candidate = $this->turn->offer();

        if (! $candidate || ! $this->turn->mayPitch() || ! $this->turn->decision) {
            return 'Do not suggest an activity in this reply.';
        }

        $words = trim($request->string('guest_words')->toString());

        if ($words === '' || ! str_contains($this->normalise($this->turn->messageText), $this->normalise($words))) {
            return 'Those are not the guest\'s words from this message. Do not suggest an activity in this reply.';
        }

        $refusal = DB::transaction(function () use ($candidate) {
            // Two messages handled by two workers at once must not both pitch.
            // The budget is the reservation's, shared by all its rooms, so the
            // lock is on the reservation (the same one proactive pitches take).
            Reservation::withoutGlobalScope('hotel')->whereKey($this->stay->reservation_id)->lockForUpdate()->first();

            $report = app(PitchEligibilityService::class)->evaluateOpeningGates($this->stay, $this->turn->decision->opening, new GateReport);

            if (! $report->passed()) {
                return 'The pitch limit for this stay has been reached. Do not suggest an activity in this reply.';
            }

            // Approved, never offered and still on the menu right now: an
            // approver may have rejected it since the turn began (FR-015).
            $staged = Recommendation::query()
                ->whereKey($candidate->recommendationId)
                ->offerable()
                ->update(['pitch_decision_id' => $this->turn->decision->id]) === 1;

            if (! $staged) {
                return 'That suggestion is no longer available. Do not suggest an activity in this reply.';
            }

            PitchDecision::withoutGlobalScope('hotel')
                ->whereKey($this->turn->decision->id)
                ->update(['is_retry' => $this->turn->isRetryTurn, 'chosen_rank' => $candidate->storedRank]);

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        $this->turn->stage($candidate, $candidate->storedRank);

        return "Staged recommendation {$candidate->recommendationId} for {$candidate->name}. Mention it in this reply, briefly, after answering the guest. "
            ."If they agree, pass recommendation_id {$candidate->recommendationId} to the booking tool. Record their reaction with the update-recommendation tool.";
    }

    /**
     * Case-insensitive, whitespace-collapsed, the same as the turn classifier.
     */
    private function normalise(string $text): string
    {
        return Str::of($text)->lower()->squish()->toString();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_words' => $schema->string()
                ->description('The exact words in the guest\'s current message that make a suggestion welcome.')
                ->required(),
        ];
    }
}
