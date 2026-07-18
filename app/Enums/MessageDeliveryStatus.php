<?php

namespace App\Enums;

enum MessageDeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
}
