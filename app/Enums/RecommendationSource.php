<?php

namespace App\Enums;

/**
 * Where a recommendation was generated, shown in the approval queue.
 */
enum RecommendationSource: string
{
    case STAFF_REQUEST = 'staff_request';   // staff asked for recommendations for a reservation
    case CONVERSATION = 'conversation';     // generated during a guest's WhatsApp turn
    case LEGACY = 'legacy';                 // existed before sources were recorded
}
