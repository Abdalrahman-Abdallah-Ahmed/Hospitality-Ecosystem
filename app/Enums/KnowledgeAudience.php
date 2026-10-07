<?php

namespace App\Enums;

/**
 * Who will read a knowledge search's results. Guests are never given the
 * title or location of a global source: that is enforced in the tool's
 * output, not left to the agent's instructions.
 */
enum KnowledgeAudience: string
{
    case STAFF = 'staff';
    case GUEST = 'guest';
}
