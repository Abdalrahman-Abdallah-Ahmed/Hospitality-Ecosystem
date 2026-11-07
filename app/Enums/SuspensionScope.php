<?php

namespace App\Enums;

/**
 * What a suspended response says was suspended: body.scope.
 */
enum SuspensionScope: string
{
    case ACCOUNT = 'account';
    case HOTEL = 'hotel';
}
