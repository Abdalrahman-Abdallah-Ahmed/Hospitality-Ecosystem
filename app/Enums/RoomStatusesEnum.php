<?php

namespace App\Enums;

enum RoomStatusesEnum: string
{
    case AVAILABLE = 'available';
    case OCCUPIED = 'occupied';
    case MAINTENANCE = 'maintenance';
}
