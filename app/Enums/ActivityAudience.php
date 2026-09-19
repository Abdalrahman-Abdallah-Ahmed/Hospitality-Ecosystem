<?php

namespace App\Enums;

/**
 * Who an activity is designed for. Used to rank, never to exclude: parents
 * may well want the couples' spa while the children are at kids' club.
 * A null audience means "not stated", not "all".
 */
enum ActivityAudience: string
{
    case ALL = 'all';                  // suits anyone
    case FAMILY = 'family';            // designed with children in mind
    case ADULTS_ONLY = 'adults_only';  // not suitable for, or not open to, children
}
