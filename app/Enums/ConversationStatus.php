<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case WAITING_FOR_GUEST = 'waiting_for_guest';
    case CLOSED = 'closed';
    case ARCHIVED = 'archived';
}
