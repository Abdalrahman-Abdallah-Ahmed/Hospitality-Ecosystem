<?php

namespace App\Enums;

enum ConversationChannel: string
{
    case WHATSAPP = 'whatsapp';
    case SMS = 'sms';
    case WEB = 'web';
    case VOICE = 'voice';
}
