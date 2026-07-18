<?php

namespace App\Enums;

enum MessageType: string
{
    case TEXT = 'text';
    case TEMPLATE = 'template';
    case IMAGE = 'image';
    case AUDIO = 'audio';
    case SYSTEM = 'system';
}
