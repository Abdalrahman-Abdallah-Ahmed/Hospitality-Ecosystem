<?php

namespace App\Enums;

enum AiInsightCategories: string
{
    case RESERVATION = 'reservation';
    case TASK = 'task';
    case GUEST_MESSAGE = 'guest_message';
    case GENERAL = 'general';

    // Rests only on knowledge sources (policies, articles, documents): what the
    // hotel says, not what happened, so never above hypothesis level.
    case KNOWLEDGE = 'knowledge';
}
