<?php

namespace App\Enums;

enum AiInsightCategories: string
{
    case RESERVATION = 'reservation';
    case TASK = 'task';
    case GUEST_MESSAGE = 'guest_message';
    case GENERAL = 'general';
}
