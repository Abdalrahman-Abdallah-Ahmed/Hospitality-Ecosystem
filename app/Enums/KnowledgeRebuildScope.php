<?php

namespace App\Enums;

enum KnowledgeRebuildScope: string
{
    case ALL = 'all';
    case HOTEL = 'hotel';
    case GLOBAL = 'global';
}
