<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case EMPLOYEE = 'employee';
    case SUPER_ADMIN = 'super_admin';
}
