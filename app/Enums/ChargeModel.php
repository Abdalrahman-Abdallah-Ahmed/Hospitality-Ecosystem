<?php

namespace App\Enums;

/**
 * How a booked item is paid for — if at all.
 *
 * INCLUDED is the case that makes the whole booking entity necessary. An
 * all-inclusive guest who books an included activity pays nothing, and the
 * recommendation worked perfectly. Such a booking will NEVER produce a
 * transaction, and must never be treated as unrealised, unsettled, or failed.
 *
 * Someone will otherwise "fix" the reporting by joining bookings to
 * transactions and announcing a 40% failure rate that does not exist.
 */
enum ChargeModel: string
{
    case INCLUDED = 'included';         // all-inclusive: no money will ever move
    case PAY_ON_SITE = 'pay_on_site';   // settles at the outlet
    case FOLIO = 'folio';               // posts to the room, settles at checkout
    case PREPAID = 'prepaid';           // already paid before the stay

    /**
     * Whether money is ever expected for this booking. Reports call this
     * rather than re-deriving the rule and getting it wrong.
     */
    public function settles(): bool
    {
        return $this !== self::INCLUDED;
    }
}
