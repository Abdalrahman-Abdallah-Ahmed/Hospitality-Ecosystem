<?php

namespace App\Enums;

/**
 * What actually happened to a recommendation.
 *
 * Two distinctions here carry the weight of the whole package:
 *
 * ACCEPTED vs BOOKED — the guest saying yes and a commitment existing are not
 * the same event. The distance between them is the fulfilment gap: guests who
 * agreed and never ended up with a booking, because the desk was closed or the
 * price at the counter didn't match the price in the chat. Collapsing the two
 * destroys the most useful diagnostic the system has.
 *
 * NOT_DELIVERED vs DECLINED — one is a process failure (the guest never saw
 * the offer), the other a commercial signal (they saw it and said no). They
 * need different fixes from different teams.
 */
enum OutcomeType: string
{
    case NOT_DELIVERED = 'not_delivered';  // generated, never reached the guest
    case DELIVERED = 'delivered';          // offer made, no decision yet
    case DECLINED = 'declined';            // guest refused
    case ACCEPTED = 'accepted';            // guest agreed, no booking yet
    case BOOKED = 'booked';                // commitment exists — THE CONVERSION
    case EXPIRED = 'expired';              // guest departed undecided

    /**
     * Whether the nightly matching job can produce this outcome at all.
     *
     * It can only see bookings, so it reaches two of the six. A refusal and an
     * undelivered offer leave no downstream record of any kind — only the
     * conversation or a person contains those, which is why capture exists.
     */
    public function reachableByInference(): bool
    {
        return match ($this) {
            self::BOOKED, self::EXPIRED => true,
            default => false,
        };
    }

    /**
     * Whether the guest got as far as agreeing. BOOKED counts: a guest who
     * booked necessarily accepted first. Used by the conversion report, where
     * `accepted` is cumulative so that acceptance and booking rates are
     * comparable and their difference is the fulfilment gap.
     */
    public function reachedAcceptance(): bool
    {
        return match ($this) {
            self::ACCEPTED, self::BOOKED => true,
            default => false,
        };
    }
}
