<?php

namespace App\Enums;

/**
 * A user's account-wide role. An account has at most one owner, which the
 * users_one_owner_per_account index guarantees.
 */
enum GroupRole: string
{
    case OWNER = 'owner';
}
