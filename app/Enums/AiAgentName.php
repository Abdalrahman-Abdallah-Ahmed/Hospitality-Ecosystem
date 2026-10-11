<?php

namespace App\Enums;

/**
 * The agent an AI activity entry belongs to.
 */
enum AiAgentName: string
{
    case CONCIERGE = 'concierge';
    case ADMIN_ADVISOR = 'admin_advisor';
    case RECOMMENDATION = 'recommendation';
    case INSIGHTS = 'insights';
    case TURN_SIGNAL = 'turn_signal';
    case DOCUMENT_VISION = 'document_vision';
    case GUEST_MEMORY = 'guest_memory';
    case KNOWLEDGE = 'knowledge';       // embedding-only runs (knowledge sync)
    case UNATTRIBUTED = 'unattributed'; // the caller named no agent; visible, never guessed
}
