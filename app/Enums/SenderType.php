<?php

namespace App\Enums;

enum SenderType: string
{
    case ADMIN = 'admin';
    case GUEST = 'guest';
    case UNKNOWN = 'unknown';
}
