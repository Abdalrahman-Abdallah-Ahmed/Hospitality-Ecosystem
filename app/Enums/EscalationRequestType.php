<?php

namespace App\Enums;

/**
 * A guest's request about what the Concierge remembers, passed to staff
 * (SPEC-084 FR-037).
 */
enum EscalationRequestType: string
{
    case SEE_MEMORIES = 'see_memories';
    case FORGET_MEMORY = 'forget_memory';
}
