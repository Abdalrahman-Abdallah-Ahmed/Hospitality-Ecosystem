<?php

namespace App\Enums;

/**
 * A user's account-wide role. Used for validation only: the column is not
 * cast, because the tenant user endpoint can still write any string to it
 * until super admins lose hotel access (design stage 6).
 */
enum GroupRole: string
{
    case OWNER = 'owner';
    case GROUP_ADMIN = 'group_admin';
}
