<?php

namespace App\Enums;

/**
 * The lifecycle of a commitment. Note what is absent: nothing here refers to
 * money. A booking's status is never derived from whether a transaction
 * exists, in either direction — see BookingService.
 */
enum BookingStatus: string
{
    case PENDING = 'pending';       // guest accepted, slot not yet confirmed
    case CONFIRMED = 'confirmed';   // slot held, guest expected
    case REALISED = 'realised';     // guest attended / consumed
    case NO_SHOW = 'no_show';       // confirmed, guest never came
    case CANCELLED = 'cancelled';   // cancelled before the date

    /**
     * The statuses whose party takes places on the activity's dates. A
     * pending booking holds its places from the start, so nothing is sold
     * twice while staff have yet to confirm it.
     *
     * @return list<self>
     */
    public static function holdingCapacity(): array
    {
        return [self::PENDING, self::CONFIRMED, self::REALISED];
    }

    /**
     * Whether its date, time, party or notes can still be changed. An attended
     * or withdrawn booking is history.
     */
    public function isEditable(): bool
    {
        return $this === self::PENDING || $this === self::CONFIRMED;
    }
}
