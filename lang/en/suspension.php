<?php

use App\Jobs\ProcessInboundWhatsAppMessageJob;

return [
    'account' => 'This account has been suspended.',
    'hotel' => 'This hotel has been suspended.',

    'guest_reply' => ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY,
];
