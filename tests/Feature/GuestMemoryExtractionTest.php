<?php

use App\Ai\Agents\GuestMemoryAgent;
use App\Enums\AiTriggerKind;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Jobs\RetryGuestMemoryExtractionsJob;
use App\Models\AiActivity;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Models\GuestMemoryExtraction;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\AiCost\AiSpendCeiling;
use App\Services\StayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;

/*
 * SPEC-084: durable facts are extracted once, after a guest's last
 * check-out on a reservation, from what they said during the stay — and
 * only facts that are safe and worth keeping.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Sleep::fake();
});

/**
 * An in-house guest who talked to the Concierge during the stay (and once
 * long before it).
 *
 * @return array{0: Guest, 1: Reservation, 2: Stay}
 */
function memStay(Hotel $hotel, array $roomNumbers = ['101'], bool $talked = true): array
{
    [$guest, $reservation, $stay] = rapInHouseStay($hotel, roomNumbers: $roomNumbers);

    if ($talked) {
        cmaGuestConversation($guest, [
            ['role' => 'user', 'content' => 'Last year I asked about parking.', 'at' => now()->subDays(400)],
            ['role' => 'user', 'content' => 'I am vegetarian, any dinner ideas?', 'at' => now()->subHours(3)],
            ['role' => 'assistant', 'content' => 'Try our garden tasting menu.', 'at' => now()->subHours(3)->addMinute()],
        ]);
    }

    return [$guest, $reservation, $stay];
}

function memCheckOut(Stay $stay): void
{
    app(StayLifecycleService::class)->checkOut($stay->fresh());
}

/**
 * @param  list<array<string, mixed>>  $operations
 */
function memAgentSays(array $operations): void
{
    GuestMemoryAgent::fake([['operations' => $operations]]);
}

function memCreate(string $content, string $category = 'preference', string $confidence = 'high', string $language = 'en'): array
{
    return ['op' => 'create', 'category' => $category, 'content' => $content, 'language' => $language, 'confidence' => $confidence];
}

function memExtraction(Guest $guest): ?GuestMemoryExtraction
{
    return GuestMemoryExtraction::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->first();
}

function memRun(Guest $guest, Reservation $reservation): void
{
    app()->call([new ExtractGuestMemoriesJob($guest->hotel_id, $guest->id, $reservation->id), 'handle']);
}

it('extracts memories once the guest has checked out, from what they said during the stay', function () {
    $hotel = cmaHotel();
    [$guest, $reservation, $stay] = memStay($hotel);
    memAgentSays([memCreate('Vegetarian.')]);

    memCheckOut($stay);

    $memory = GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->sole();

    expect($memory)
        ->hotel_id->toBe($hotel->id)
        ->category->value->toBe('preference')
        ->content->toBe('Vegetarian.')
        ->language->toBe('en')
        ->confidence->value->toBe('high')
        ->source_stay_id->toBe($stay->id)
        ->and(memExtraction($guest))
        ->status->value->toBe('extracted')
        ->memories_written->toBe(1)
        ->attempts->toBe(1);

    GuestMemoryAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'I am vegetarian')
        && str_contains($prompt->prompt, 'garden tasting menu')
        && ! str_contains($prompt->prompt, 'parking'));
});

it('waits for the last of the guest\'s rooms on the reservation to check out', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = memStay($hotel, ['101', '102']);
    $stays = Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->orderBy('id')->get();
    $stays->each(fn (Stay $stay) => $stay->forceFill(['guest_id' => $guest->id])->saveQuietly());
    memAgentSays([memCreate('Vegetarian.')]);

    memCheckOut($stays[0]);

    expect(memExtraction($guest))->toBeNull();

    memCheckOut($stays[1]);

    expect(memExtraction($guest)->status->value)->toBe('extracted');
});

it('extracts once, however often it is triggered', function () {
    $hotel = cmaHotel();
    [$guest, $reservation, $stay] = memStay($hotel);
    $calls = 0;
    GuestMemoryAgent::fake(function () use (&$calls) {
        $calls++;

        return ['operations' => [memCreate('Vegetarian.')]];
    });

    memCheckOut($stay);
    memRun($guest, $reservation);
    memRun($guest, $reservation);

    expect($calls)->toBe(1)
        ->and(GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->count())->toBe(1)
        ->and(GuestMemoryExtraction::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('skips a stay with nothing the guest said, without calling the AI', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel, talked: false);
    memAgentSays([memCreate('Should not happen.')]);

    memCheckOut($stay);

    expect(memExtraction($guest)->status->value)->toBe('skipped_no_messages')
        ->and(GuestMemory::withoutGlobalScope('hotel')->count())->toBe(0);
    GuestMemoryAgent::assertNotPrompted(fn () => true);
});

it('skips a hotel that switched memories off, for good', function () {
    $hotel = cmaHotel();
    $hotel->forceFill(['guest_memories_enabled' => false])->save();
    [$guest, $reservation, $stay] = memStay($hotel);
    memAgentSays([memCreate('Vegetarian.')]);

    memCheckOut($stay);
    $hotel->forceFill(['guest_memories_enabled' => true])->save();
    memRun($guest, $reservation);

    expect(memExtraction($guest)->status->value)->toBe('skipped_disabled')
        ->and(GuestMemory::withoutGlobalScope('hotel')->count())->toBe(0);
    GuestMemoryAgent::assertNotPrompted(fn () => true);
});

it('keeps only safe, durable, confident facts that belong to this guest', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel);
    $other = cmaMemory(gsGuest($hotel), ['content' => 'Someone else.']);
    $quiet = cmaMemory($guest, ['content' => 'Likes quiet rooms.', 'confidence' => 'medium', 'extracted_at' => now()->subYear()]);
    $old = cmaMemory($guest, ['category' => 'communication', 'content' => 'يفضّل التواصل صباحاً.', 'language' => 'ar']);

    memAgentSays([
        memCreate('Vegetarian.'),
        memCreate('Might like golf.', 'interest', 'low'),
        memCreate(str_repeat('Very long. ', 30)),
        memCreate('Card 4111 1111 1111 1111 on file.', 'other'),
        memCreate('Email is lina@example.com.', 'communication'),
        memCreate('Passport X1234567.', 'other'),
        ['op' => 'refresh', 'id' => $quiet->id, 'confidence' => 'high'],
        ['op' => 'refresh', 'id' => $other->id, 'confidence' => 'high'],
        ['op' => 'replace', 'id' => $old->id, 'category' => 'communication', 'content' => 'Prefers messages in the evening.', 'language' => 'en', 'confidence' => 'medium'],
        ['op' => 'replace', 'id' => $other->id, 'category' => 'other', 'content' => 'Hijacked.', 'language' => 'en', 'confidence' => 'high'],
    ]);

    memCheckOut($stay);

    $memories = GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->get()->keyBy('content');

    expect($memories->keys()->sort()->values()->all())->toBe(['Likes quiet rooms.', 'Prefers messages in the evening.', 'Vegetarian.'])
        ->and($memories['Likes quiet rooms.']->confidence->value)->toBe('high')
        ->and($memories['Likes quiet rooms.']->source_stay_id)->toBe($stay->id)
        ->and($memories['Likes quiet rooms.']->extracted_at->isToday())->toBeTrue()
        ->and($memories['Prefers messages in the evening.']->id)->toBe($old->id)
        ->and($memories['Prefers messages in the evening.']->language)->toBe('en')
        ->and($other->fresh()->content)->toBe('Someone else.')
        ->and($other->fresh()->confidence->value)->toBe('high');
});

it('never lowers a memory\'s confidence on a refresh', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel);
    $memory = cmaMemory($guest, ['confidence' => 'high']);
    memAgentSays([['op' => 'refresh', 'id' => $memory->id, 'confidence' => 'medium']]);

    memCheckOut($stay);

    expect($memory->fresh()->confidence->value)->toBe('high');
});

it('keeps at most 20 memories, dropping the oldest, least confident first', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel);
    $weakest = cmaMemory($guest, ['content' => 'Oldest medium.', 'confidence' => 'medium', 'extracted_at' => now()->subYears(2)]);
    cmaMemory($guest, ['content' => 'Newer medium.', 'confidence' => 'medium', 'extracted_at' => now()->subYear()]);
    foreach (range(1, 18) as $i) {
        cmaMemory($guest, ['content' => "High {$i}.", 'confidence' => 'high', 'extracted_at' => now()->subYears(3)]);
    }
    memAgentSays([memCreate('Vegetarian.')]);

    memCheckOut($stay);

    $contents = GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->pluck('content');

    expect($contents)->toHaveCount(20)
        ->toContain('Vegetarian.', 'Newer medium.')
        ->not->toContain('Oldest medium.')
        ->and(GuestMemory::withoutGlobalScope('hotel')->find($weakest->id))->toBeNull();
});

it('retries a failed extraction once, a day later, then gives up', function () {
    $hotel = cmaHotel();
    [$guest, $reservation, $stay] = memStay($hotel);
    GuestMemoryAgent::fake(fn () => throw new RuntimeException('provider down'));

    memCheckOut($stay);

    $extraction = memExtraction($guest);
    expect($extraction->status->value)->toBe('pending_retry')
        ->and($extraction->attempts)->toBe(1)
        ->and($extraction->last_error)->toBe('RuntimeException')
        ->and($extraction->retry_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();

    // Not due yet: nothing happens.
    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);
    expect(memExtraction($guest)->attempts)->toBe(1);

    $this->travel(25)->hours();
    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);

    expect(memExtraction($guest))
        ->status->value->toBe('failed')
        ->attempts->toBe(2);

    $this->travel(25)->hours();
    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);

    expect(memExtraction($guest)->attempts)->toBe(2);
});

it('retries an extraction blocked by the spend ceiling once, then records it as skipped', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel);
    memAgentSays([memCreate('Vegetarian.')]);
    $this->mock(AiSpendCeiling::class, fn ($mock) => $mock->shouldReceive('assertNotExceeded')
        ->andThrow(new AiSpendCeilingExceededException($hotel->hotelGroup, 50.0, 50.0)));

    memCheckOut($stay);

    expect(memExtraction($guest)->status->value)->toBe('pending_retry');

    $this->travel(25)->hours();
    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);

    expect(memExtraction($guest)->status->value)->toBe('skipped_spend_ceiling')
        ->and(GuestMemory::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('audits the extraction as an AI run and an AI change, without the memory\'s words', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = memStay($hotel);
    memAgentSays([memCreate('Allergic to shellfish.', 'other')]);

    memCheckOut($stay);

    $run = AiActivity::withoutGlobalScope('hotel')->where('category', 'ai_run')->where('agent', 'guest_memory')->sole();
    $change = AiActivity::withoutGlobalScope('hotel')->where('tool', 'ExtractGuestMemoriesJob')->sole();
    $event = EventLog::withoutGlobalScope('hotel')->where('event_type', 'guest_memory.created')->sole();

    expect($run->trigger_kind)->toBe(AiTriggerKind::SCHEDULED_JOB)
        ->and($change->agent->value)->toBe('guest_memory')
        ->and($change->result->value)->toBe('succeeded')
        ->and($change->ai_run_id)->toBe($run->id)
        ->and(memExtraction($guest)->ai_run_id)->toBe($run->id)
        ->and(json_encode($event->toArray()))->not->toContain('shellfish')
        ->and(json_encode($change->getAttributes()))->not->toContain('shellfish');
});
