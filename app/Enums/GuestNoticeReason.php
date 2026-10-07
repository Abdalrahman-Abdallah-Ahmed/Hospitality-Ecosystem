<?php

namespace App\Enums;

/**
 * Why a guest notice was not sent.
 */
enum GuestNoticeReason: string
{
    case CANCELLED = 'cancelled';       // staff cancelled the request; nothing to report
    case NO_CONTACT = 'no_contact';     // outside the window (or no phone) and no email on file
    case SEND_FAILED = 'send_failed';   // every channel failed after its retries
}
