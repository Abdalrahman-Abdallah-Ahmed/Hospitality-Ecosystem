<?php

namespace App\Enums;

/**
 * Who (or what) caused a logged event.
 */
enum ActorKind: string
{
    case USER = 'user';         // an authenticated human, recorded in `actor`
    case SYSTEM = 'system';     // a scheduled job or internal process, no human
    case AI_AGENT = 'ai_agent'; // a record written by an LLM agent or its tools
    case IMPORT = 'import';     // a bulk spreadsheet import
}
