<?php

namespace App\Enums;

enum GuestMemoryCategory: string
{
    case PREFERENCE = 'preference';       // room, food, amenities
    case COMMUNICATION = 'communication'; // language, channel, tone, times
    case INTEREST = 'interest';           // activities, experiences
    case OTHER = 'other';                 // another durable fact, e.g. an accessibility need
}
