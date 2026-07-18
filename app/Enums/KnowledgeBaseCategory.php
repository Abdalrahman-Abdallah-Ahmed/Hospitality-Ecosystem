<?php

namespace App\Enums;

enum KnowledgeBaseCategory: string
{
    case HOSPITALITY_BEST_PRACTICES = 'hospitality_best_practices';
    case GUEST_PERSONAS = 'guest_personas';
    case COMMUNICATION_GUIDELINES = 'communication_guidelines';
    case REVENUE_STRATEGIES = 'revenue_strategies';
    case DESTINATION_KNOWLEDGE = 'destination_knowledge';
    case SEASONAL_KNOWLEDGE = 'seasonal_knowledge';
    case RECOMMENDATION_RULES = 'recommendation_rules';
}
