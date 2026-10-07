<?php

namespace App\Enums;

/**
 * The label a search result carries: the searching hotel's own knowledge, or
 * the shared platform knowledge every hotel reads.
 */
enum KnowledgeScope: string
{
    case HOTEL = 'hotel';
    case GENERAL = 'general';
}
