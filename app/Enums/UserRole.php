<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case WORKER = 'worker';
    case SUPER_ADMIN = 'super_admin';
}
