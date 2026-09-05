<?php

namespace App\Enums;

/**
 * How we found out what happened to a recommendation.
 *
 * Four different things can tell us, and they are not equally trustworthy.
 * Three of them observed the event; one of them guessed. Every outcome must
 * state which, with no default — an unlabelled attribution is worthless,
 * because nobody downstream can tell a fact from an estimate.
 */
enum AttributionMethod: string
{
    case CONVERSATIONAL = 'conversational';  // the guest's own words in the exchange
    case DIRECT = 'direct';                  // a booking carries the recommendation_id
    case STAFF = 'staff';                    // a person recorded the outcome
    case INFERRED = 'inferred';              // the nightly matching job decided it
    case NONE = 'none';                      // no link established

    /**
     * Evidence level follows mechanically from the method, so the two can
     * never drift apart. Callers derive it from here; they never set it.
     *
     * The three observed methods are L1 — someone watched it happen.
     * Inference is L2: careful, but still a guess, since the guest may have
     * booked anyway. NONE is L4, because nothing was observed at all and the
     * row exists only because everything else was ruled out.
     */
    public function evidenceLevel(): EvidenceLevel
    {
        return match ($this) {
            self::CONVERSATIONAL, self::DIRECT, self::STAFF => EvidenceLevel::L1,
            self::INFERRED => EvidenceLevel::L2,
            self::NONE => EvidenceLevel::L4,
        };
    }

    /**
     * Which method wins when two disagree: DIRECT > CONVERSATIONAL > STAFF >
     * INFERRED. A booking carrying the recommendation id is the hardest fact
     * available; a matching job's guess is the softest.
     *
     * This is what stops the nightly job overwriting "the guest told us no"
     * with "they booked it anyway, probably".
     */
    public function precedence(): int
    {
        return match ($this) {
            self::DIRECT => 4,
            self::CONVERSATIONAL => 3,
            self::STAFF => 2,
            self::INFERRED => 1,
            self::NONE => 0,
        };
    }
}
