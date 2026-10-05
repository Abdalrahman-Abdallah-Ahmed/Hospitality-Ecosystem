<?php

namespace App\Enums;

/**
 * Whether a room can take a guest, independent of how clean it is (D5). An
 * occupied room can be dirty or clean; an out-of-order room is out of service
 * (a fault, a leak, an unfinished repair) and must not be sold or assigned.
 */
enum RoomStatusesEnum: string
{
    case AVAILABLE = 'available';
    case OCCUPIED = 'occupied';
    case OUT_OF_ORDER = 'out_of_order';

    /**
     * The statuses that take a room out of sale.
     *
     * @return list<self>
     */
    public static function outOfOrder(): array
    {
        return [self::OUT_OF_ORDER];
    }
}
