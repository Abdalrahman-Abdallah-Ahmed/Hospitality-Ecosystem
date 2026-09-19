<?php

namespace App\Enums;

enum InboundMessageStatus: string
{
    /** Accepted and queued for a reply. */
    case RECEIVED = 'received';

    /** Dropped by the per-sender rate limit; no reply is generated. */
    case THROTTLED = 'throttled';

    /** Handled as a device-pairing code rather than a conversation turn. */
    case PAIRING = 'pairing';

    case REPLIED = 'replied';

    /** The reply could not be delivered after every retry. */
    case FAILED = 'failed';
}
