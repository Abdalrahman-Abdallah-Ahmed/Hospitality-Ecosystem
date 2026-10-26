<?php

/*
| Entry-point inventory (spec 017, research R1).
|
| The entry points themselves are DISCOVERED by the tests (routes, queued jobs,
| scheduled events, AI tools; see Tests\Support\EntryPointDiscovery). This file
| only holds what discovery cannot decide on its own: which test covers an
| entry point the generic checks cannot drive, which jobs deliberately cross
| hotels and why, intended global data, and list routes that return a fixed
| shape rather than a list. EntryPointInventoryTest fails when an entry point
| is neither covered nor listed here, or when an entry here no longer exists.
|
| A route entry has either 'covered_by' (the description of an existing test,
| exactly as written in it(...)) or 'reason' (why it reads and writes no
| tenant data at all).
*/

$stamped = 'stamps hotel_id automatically on create when the model omits it';
$documents = 'keeps one hotel out of another hotel knowledge documents (SPEC-008 FR-040)';

return [
    'routes' => [
        // Creates with no foreign key to another tenant record: the new row
        // is stamped with the caller's hotel, whatever the body says.
        'POST api/activity' => ['covered_by' => $stamped],
        'POST api/activity-category' => ['covered_by' => $stamped],
        'POST api/guest' => ['covered_by' => $stamped],
        'POST api/hotel-policy' => ['covered_by' => $stamped],
        'POST api/knowledge-base-articles' => ['covered_by' => 'creates an article scoped to the caller own hotel, ignoring a spoofed hotel_id'],
        'POST api/room-types' => ['covered_by' => $stamped],
        'POST api/staff-roles' => ['covered_by' => $stamped],
        'POST api/team' => ['covered_by' => $stamped],
        'POST api/hotel' => ['covered_by' => 'rejects a non-super-admin from creating a hotel'],
        'POST api/ai-insights' => ['covered_by' => 'generates insights for the caller\'s own hotel only (spec 017)'],

        // Records addressed by a plain {id} rather than a bound model.
        'GET api/knowledge-documents/{id}' => ['covered_by' => $documents],
        'PUT api/knowledge-documents/{id}' => ['covered_by' => $documents],
        'DELETE api/knowledge-documents/{id}' => ['covered_by' => $documents],
        'GET api/knowledge-documents/{id}/download' => ['covered_by' => $documents],
        'GET api/knowledge-documents/{id}/text' => ['covered_by' => $documents],
        'PUT api/knowledge-documents/{id}/text' => ['covered_by' => $documents],
        'DELETE api/knowledge-documents/{id}/text' => ['covered_by' => $documents],
        'POST api/knowledge-documents' => ['covered_by' => $documents],
        'POST api/knowledge-documents/{id}/file' => ['covered_by' => $documents],
        'POST api/knowledge-documents/{id}/reindex' => ['covered_by' => $documents],
        'POST api/knowledge-documents/{id}/restore' => ['covered_by' => $documents],
        'GET api/history/{type}/{id}' => ['covered_by' => 'hides another hotel\'s guest memory history, and unknown ids'],

        // Bound to records the shared fixtures cannot build generically.
        'GET api/conversations/{conversation}' => ['covered_by' => 'keeps one hotel out of another hotel guest conversations (SPEC-082 FR-005)'],
        'GET api/guest/{guest}/memories' => ['covered_by' => 'keeps one hotel out of another hotel guest memories (SPEC-084)'],
        'DELETE api/guest/{guest}/memories/{memory}' => ['covered_by' => 'will not delete a memory through another guest'],
        'GET api/hotel/{hotel}' => ['covered_by' => 'rejects an admin viewing a hotel they do not own'],
        'PUT api/hotel/{hotel}' => ['covered_by' => 'rejects an admin updating a hotel they do not own'],
        'PATCH api/hotel/{hotel}' => ['covered_by' => 'rejects an admin updating a hotel they do not own'],
        'DELETE api/hotel/{hotel}' => ['covered_by' => 'rejects the owning admin from deleting their own hotel'],
        'GET api/users/{user}' => ['covered_by' => 'rejects an admin viewing a user belonging to a different hotel'],
        'PUT api/users/{user}' => ['covered_by' => 'rejects an admin updating a user belonging to a different hotel'],
        'PATCH api/users/{user}' => ['covered_by' => 'rejects an admin updating a user belonging to a different hotel'],
        'DELETE api/users/{user}' => ['covered_by' => 'rejects an admin deleting a user belonging to a different hotel'],

        // Flows the generic rules cannot drive.
        'POST api/ai-advisor/chat' => ['covered_by' => 'never shows or changes another hotel\'s records, whatever identifiers the AI passes'],
        'POST api/recommendations/decide' => ['covered_by' => 'keeps one hotel out of another hotel recommendation approvals (SPEC-071 FR-039)'],
        'POST api/reservation/import' => ['covered_by' => 'keeps one hotel out of another hotel reservation imports and their data (SPEC-100/101)'],
        'POST api/reservation/import-previews' => ['covered_by' => 'keeps one hotel out of another hotel import previews (016)'],
        'POST api/transaction/import' => ['covered_by' => 'rejects a non-admin user from importing transactions'],
        'POST api/whatsapp' => ['covered_by' => 'does not find a matching guest from a different hotel'],
        'POST api/connect' => ['covered_by' => 'creates an expiring whatsapp pairing code for an authenticated user'],

        // No tenant data read or written.
        'GET api/whatsapp' => ['reason' => 'Meta webhook verification handshake: compares a token, touches no records'],
        'POST api/logout' => ['reason' => 'Deletes only the caller\'s own access token'],
    ],
    // 'hotel': runs inside TenantContext::runForHotel (checked by JobTenantIsolationTest).
    // 'cross_hotel': deliberately crosses hotels; the reason says why.
    // 'none': reads and writes no tenant records.
    'jobs' => [
        'App\\Jobs\\CreateAiInsightsJob' => ['scope' => 'hotel'],
        'App\\Jobs\\EvaluateProactiveTriggersJob' => ['scope' => 'hotel'],
        'App\\Jobs\\ExpireRecommendationsJob' => ['scope' => 'hotel'],
        'App\\Jobs\\ExtractGuestMemoriesJob' => ['scope' => 'hotel'],
        'App\\Jobs\\GenerateActivityRecommendationsJob' => ['scope' => 'hotel'],
        'App\\Jobs\\IndexKnowledgeDocumentJob' => ['scope' => 'hotel'],
        'App\\Jobs\\MatchRecommendationOutcomesJob' => ['scope' => 'hotel'],
        'App\\Jobs\\PrepareReservationImportJob' => ['scope' => 'hotel'],
        'App\\Jobs\\ProcessInboundWhatsAppMessageJob' => ['scope' => 'hotel'],
        'App\\Jobs\\SendGuestRequestNoticeJob' => ['scope' => 'hotel'],
        'App\\Jobs\\SendProactiveMessageJob' => ['scope' => 'hotel'],
        'App\\Jobs\\StartHousekeepingDayJob' => ['scope' => 'hotel'],
        'App\\Jobs\\SyncKnowledgeChunksJob' => ['scope' => 'hotel'],
        'App\\Jobs\\WriteReservationImportJob' => ['scope' => 'hotel'],
        'App\\Jobs\\PruneReservationImportPreviewsJob' => ['scope' => 'cross_hotel', 'reason' => 'Platform-wide removal of expired import previews and their files'],
        'App\\Jobs\\PurgeDeletedKnowledgeDocumentsJob' => ['scope' => 'cross_hotel', 'reason' => 'Platform-wide removal of knowledge documents deleted more than 30 days ago'],
        'App\\Jobs\\RetryGuestMemoryExtractionsJob' => ['scope' => 'cross_hotel', 'reason' => 'Finds every hotel\'s due retries; each ExtractGuestMemoriesJob it dispatches scopes itself to one hotel'],
        'App\\Jobs\\Metering\\RebuildUsageCountersJob' => ['scope' => 'cross_hotel', 'reason' => 'Account-level (HotelGroup) metering rebuilt from meter events'],
        'App\\Jobs\\AiCost\\FlagAiCostOverrunsJob' => ['scope' => 'cross_hotel', 'reason' => 'Account-level (HotelGroup) AI cost check'],
        'App\\Jobs\\SendWhatsAppMessageJob' => ['scope' => 'none', 'reason' => 'Sends prepared text to a phone number; reads no records'],
    ],
    // Every scheduled entry in routes/console.php; each is a job listed above
    // or a command that reads no tenant data.
    'scheduled' => [
        'App\\Jobs\\AiCost\\FlagAiCostOverrunsJob',
        'App\\Jobs\\EvaluateProactiveTriggersJob',
        'App\\Jobs\\ExpireRecommendationsJob',
        'App\\Jobs\\MatchRecommendationOutcomesJob',
        'App\\Jobs\\Metering\\RebuildUsageCountersJob',
        'App\\Jobs\\PruneReservationImportPreviewsJob',
        'App\\Jobs\\PurgeDeletedKnowledgeDocumentsJob',
        'App\\Jobs\\RetryGuestMemoryExtractionsJob',
        'App\\Jobs\\StartHousekeepingDayJob',
        'queue:check-waits',
    ],
    // Intended global records (FR-007): visible to the AI for every hotel,
    // never readable or changeable through the hotel routes.
    'global_data' => [
        'App\\Models\\KnowledgeBaseArticle' => ['covered_by' => 'does not let a super admin read, change or delete a global article through the hotel routes'],
        'App\\Models\\KnowledgeDocument' => ['covered_by' => 'keeps global documents out of reach on the hotel routes, download included'],
        'App\\Models\\AiModelPrice' => ['reason' => 'Platform price list with no hotel_id; read only by cost attribution'],
    ],
    'route_exceptions' => [
        // GET routes that are not paginated lists, and why that is bounded (FR-018).
        'bounded_list' => [
            'GET api/dashboard' => 'Fixed-shape daily figures; its lists are one day of the hotel\'s arrivals, departures and VIPs',
            'GET api/analytics/conversion' => 'Fixed-shape report',
            'GET api/usage' => 'Fixed-shape usage report',
            'GET api/permissions' => 'The permission enum, a fixed list',
            'GET api/available-channels' => 'The booking channel enum, a fixed list',
            'GET api/user' => 'The caller\'s own user record',
            'GET api/housekeeping/board' => 'One row per room of the hotel: bounded by its room count',
            'GET api/stays/arrivals' => 'One day\'s front-desk list: bounded by the hotel\'s rooms; overdue arrivals are cleared by no-show',
            'GET api/stays/departures' => 'One day\'s front-desk list: bounded by the hotel\'s rooms',
            'GET api/stays/in-house' => 'Stays in house now: bounded by the hotel\'s rooms',
        ],
    ],
];
