<?php

/*
| AI tool inventory exceptions (spec 017, research R4–R6).
|
| The tools, their agents, kinds and permissions come from AdminToolset and
| each agent's tools(); AiToolInventoryTest reads those. This file only lists
| tools no agent uses (with a reason), the guest fields a tool may return to
| the model (pii_fields), and read tools that are bounded by nature
| (naturally_bounded) rather than through ListResult.
*/

return [
    'unused' => [
        'App\\Ai\\Tools\\CreateHotelPolicyTool' => 'Policies are quoted to guests as the hotel\'s own word, so no agent may write one (AdminCreateToolsTest)',
    ],

    // Tool => the guest contact fields it may hand the model, and why.
    'pii_fields' => [
        'App\\Ai\\Tools\\GetGuestTool' => ['phone_number', 'email'],
    ],

    'naturally_bounded' => [
        'App\\Ai\\Tools\\GetAvailabilityTool' => 'One row per room type per night of the asked range',
        'App\\Ai\\Tools\\GetGuestAvailabilityTool' => 'One row per room type per night of the asked range',
        'App\\Ai\\Tools\\GetGuestMessagesTool' => 'The last 20 messages of one guest',
        'App\\Ai\\Tools\\GetGuestTool' => 'One guest, with a fixed number of history rows',
        'App\\Ai\\Tools\\GetHotelSettingsTool' => 'One hotel\'s settings',
        'App\\Ai\\Tools\\GetOwnBookingsTool' => 'One guest\'s upcoming bookings and a fixed number of past ones',
        'App\\Ai\\Tools\\GetOwnRequestsTool' => 'One guest\'s open requests',
        'App\\Ai\\Tools\\GetOwnReservationTool' => 'One reservation',
        'App\\Ai\\Tools\\GetRecommendationsTool' => 'The recommendations offered on one reservation',
        'App\\Ai\\Tools\\GetReportTool' => 'Fixed-shape reports; their ranked lists stop at 20',
        'App\\Ai\\Tools\\GetReservationTool' => 'One reservation',
        'App\\Ai\\Tools\\GetTaskCategoriesTool' => 'The hotel\'s own category configuration, a short fixed list',
        'App\\Ai\\Tools\\KnowledgeSearchTool' => 'Top-k search results',
        'App\\Ai\\Tools\\InsightKnowledgeSearchTool' => 'Top-k search results',
    ],
];
