<?php

namespace App\Enums;

enum RecommendationStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case PURCHASED = 'purchased';
    case IGNORED = 'ignored';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}
