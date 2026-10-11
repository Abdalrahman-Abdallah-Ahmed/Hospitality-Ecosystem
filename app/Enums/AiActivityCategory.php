<?php

namespace App\Enums;

/**
 * What kind of thing an AI activity entry records (SPEC-083, FR-035).
 */
enum AiActivityCategory: string
{
    case DATA_CHANGE = 'data_change';       // an AI write action on hotel data
    case GUEST_OUTREACH = 'guest_outreach'; // a pitch and its outcome, a proactive message
    case AI_RUN = 'ai_run';                 // one agent turn or AI job, with its usage
}
