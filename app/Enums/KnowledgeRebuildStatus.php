<?php

namespace App\Enums;

enum KnowledgeRebuildStatus: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
}
