<?php

namespace App\Enums;

enum ProactiveMessageStatus: string
{
    case SCHEDULED = 'scheduled';   // waiting for due_at (new, or deferred)
    case SENDING = 'sending';       // claimed by a worker
    case SENT = 'sent';             // WhatsApp accepted it
    case SKIPPED = 'skipped';       // a guardrail ruled it out; see reason
    case FAILED = 'failed';         // every send attempt threw
}
