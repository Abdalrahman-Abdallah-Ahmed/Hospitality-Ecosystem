<?php

namespace App\Enums;

enum RoomStatusesEnum: string
{
    case AVAILABLE = 'available';
    case OCCUPIED = 'occupied';
    case MAINTENANCE = 'maintenance';

    /**
     * The statuses that take a room out of sale. Until SPEC-003 splits room
     * status (D5) that is maintenance; SPEC-003 changes this to its
     * out_of_order case and availability follows without other changes.
     *
     * @return list<self>
     */
    public static function outOfOrder(): array
    {
        return [self::MAINTENANCE];
    }
}
