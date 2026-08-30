<?php

namespace App\Enums;

enum StayStatus: string
{
    case EXPECTED = 'expected';     // booked, not arrived yet
    case IN_HOUSE = 'in_house';     // checked in, still here
    case DEPARTED = 'departed';     // checked out normally
    case NO_SHOW = 'no_show';       // never arrived
    case CANCELLED = 'cancelled';   // cancelled before arrival
}
