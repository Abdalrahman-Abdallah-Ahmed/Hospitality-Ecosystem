<?php

namespace App\Enums;

enum ReservationChannels: string
{
    case BOOKING_COM = 'booking.com';
    case EXPEDIA = 'expedia';
    case AIRBNB = 'airbnb';
    case AGODA = 'agoda';
    case TRIPADVISOR = 'tripadvisor';
    case VRBO = 'vrbo'; 
}
