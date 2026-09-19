<?php

namespace App\Enums;

/**
 * How a turn's pitch decision ended. A decision row with no result means the
 * job died mid-turn.
 */
enum PitchResult: string
{
    case PITCHED = 'pitched';            // tool called, reply sent
    case NO_PITCH = 'no_pitch';          // eligible, agent chose not to — restraint or missed opening
    case INELIGIBLE = 'ineligible';      // a gate blocked it
    case REPLY_FAILED = 'reply_failed';  // staged, but the send threw
}
