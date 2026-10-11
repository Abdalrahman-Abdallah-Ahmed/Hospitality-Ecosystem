<?php

namespace App\Enums;

/**
 * How an AI action ended.
 */
enum AiActivityResult: string
{
    case SUCCEEDED = 'succeeded';
    case REFUSED = 'refused'; // permission, validation or a business rule; nothing changed
    case FAILED = 'failed';   // an exception; the change was rolled back
}
