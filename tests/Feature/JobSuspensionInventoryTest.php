<?php

use App\Contracts\HasHotel;
use App\Jobs\AiCost\FlagAiCostOverrunsJob;
use App\Jobs\EvaluateProactiveTriggersJob;
use App\Jobs\ExpireRecommendationsJob;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Jobs\Metering\RebuildUsageCountersJob;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Jobs\PruneReservationImportPreviewsJob;
use App\Jobs\PurgeDeletedKnowledgeDocumentsJob;
use App\Jobs\RetryGuestMemoryExtractionsJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Jobs\StartHousekeepingDayJob;
use Tests\Support\EntryPointDiscovery;

/*
| Spec 024 (FR-042, risk R4): a job that misses the suspension check would let
| a suspended hotel keep working through it. Every queued job is gated, filters
| the hotels it loops over, or is listed here with the reason it is neither.
*/

function suspensionLoopingJobs(): array
{
    return [
        EvaluateProactiveTriggersJob::class,
        ExpireRecommendationsJob::class,
        MatchRecommendationOutcomesJob::class,
        PurgeDeletedKnowledgeDocumentsJob::class,
        RetryGuestMemoryExtractionsJob::class,
        StartHousekeepingDayJob::class,
    ];
}

function suspensionExemptJobs(): array
{
    return [
        FlagAiCostOverrunsJob::class => 'Loops over accounts for a log-only margin alert',
        RebuildUsageCountersJob::class => 'Bookkeeping must stay correct during a suspension',
        PruneReservationImportPreviewsJob::class => 'Removes expired temporary uploads only',
        SendWhatsAppMessageJob::class => 'Carries no hotel: prepared text to a phone number',
        ProcessInboundWhatsAppMessageJob::class => 'Answers a suspended hotel with the fixed reply itself, before any AI run',
    ];
}

it('gates every queued job, or says why not', function () {
    foreach (EntryPointDiscovery::queuedJobs() as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (array_key_exists($class, suspensionExemptJobs())) {
            continue;
        }

        if (in_array($class, suspensionLoopingJobs(), true)) {
            expect($source)->toContain('notSuspended');

            continue;
        }

        expect(is_subclass_of($class, HasHotel::class))->toBeTrue("{$class} does not implement HasHotel")
            ->and(str_contains($source, 'SkipsWhenHotelSuspended') || str_contains($source, 'new SkipIfHotelSuspended'))->toBeTrue("{$class} does not use SkipIfHotelSuspended");
    }
});

it('finds exactly the jobs it lists', function () {
    $gated = array_filter(EntryPointDiscovery::queuedJobs(), fn (string $class) => is_subclass_of($class, HasHotel::class));

    expect($gated)->toHaveCount(10);

    foreach ([...suspensionLoopingJobs(), ...array_keys(suspensionExemptJobs())] as $class) {
        expect(EntryPointDiscovery::queuedJobs())->toContain($class);
    }
});

it('keeps the fixed reply job out of the job gate', function () {
    expect(is_subclass_of(ProcessInboundWhatsAppMessageJob::class, HasHotel::class))->toBeFalse();
});
