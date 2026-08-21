<?php

namespace App\Enums;

enum RoomTypes: string
{
    case SINGLE = 'single';
    case DOUBLE = 'double';
    case TWIN = 'twin';
    case TRIPLE = 'triple';
    case SUITE = 'suite';
    case DELUXE = 'deluxe';
    case FAMILY = 'family';
}
