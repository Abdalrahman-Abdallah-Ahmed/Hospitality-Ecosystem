<?php

use App\Contracts\HasHotel;
use App\Enums\ImportStatus;
use App\Enums\ProactiveMessageStatus;
use App\Exceptions\HotelSuspended;
use App\Jobs\CreateAiInsightsJob;
use App\Jobs\EvaluateProactiveTriggersJob;
use App\Jobs\ExpireRecommendationsJob;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Jobs\Middleware\SkipIfHotelSuspended;
use App\Jobs\PrepareReservationImportJob;
use App\Jobs\PurgeDeletedKnowledgeDocumentsJob;
use App\Jobs\RetryGuestMemoryExtractionsJob;
use App\Jobs\SendGuestRequestNoticeJob;
use App\Jobs\SendProactiveMessageJob;
use App\Jobs\SendReservationVoucherEmailJob;
use App\Jobs\StartHousekeepingDayJob;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Jobs\WriteReservationImportJob;
use App\Models\Guest;
use App\Models\GuestMemoryExtraction;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeIndexRebuild;
use App\Models\ProactiveMessage;
use App\Models\ReservationImport;
use App\Models\Task;
use App\Models\VoucherEmailRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Sentry\Event as SentryEvent;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/*
| Spec 024: a suspended hotel's background work doesn't run, and leaves no
| failure record or alert.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
    Sleep::fake();
});

function sjVoucherRequest(Hotel $hotel): VoucherEmailRequest
{
    return tap((new VoucherEmailRequest)->forceFill([
        'hotel_id' => $hotel->id,
        'reservation_id' => reservationFor($hotel)->id,
        'request_key' => 'key-'.uniqid(),
        'recipient' => 'guest@example.com',
        'language' => 'en',
        'status' => 'queued',
    ]))->save();
}

/**
 * One of the hotel jobs, built with real records.
 */
function sjHotelJob(string $name, Hotel $hotel): object
{
    $record = fn (string $model) => tenantOwnedModelFactories()[$model]($hotel);

    return match ($name) {
        'insights' => new CreateAiInsightsJob(['user' => spUser($hotel)->load('hotel')]),
        'memories' => new ExtractGuestMemoriesJob($hotel->id, (string) Str::uuid(), (string) Str::uuid()),
        'recommendations' => new GenerateActivityRecommendationsJob(reservationFor($hotel)),
        'index' => new IndexKnowledgeDocumentJob($record(KnowledgeDocument::class)->id),
        'prepare import' => new PrepareReservationImportJob($record(ReservationImport::class)->id),
        'write import' => new WriteReservationImportJob($record(ReservationImport::class)->id),
        'guest notice' => new SendGuestRequestNoticeJob($record(Task::class)->id),
        'proactive' => new SendProactiveMessageJob($record(ProactiveMessage::class)->id),
        'voucher' => new SendReservationVoucherEmailJob(sjVoucherRequest($hotel)->id),
        'sync' => new SyncKnowledgeChunksJob($record(KnowledgeBaseArticle::class)),
    };
}

dataset('suspension hotel jobs', [
    'insights', 'memories', 'recommendations', 'index', 'prepare import',
    'write import', 'guest notice', 'proactive', 'voucher', 'sync',
]);

/**
 * Whether the gate lets this job through to its work.
 */
function sjPassesGate(object $job): bool
{
    $ran = false;

    (new SkipIfHotelSuspended)->handle($job, function () use (&$ran) {
        $ran = true;
    });

    return $ran;
}

it('blocks every hotel job for a suspended hotel or account, and runs it after reactivation', function (string $name, string $level) {
    Queue::fake();
    [$account, $hotel] = spAccount();
    $job = sjHotelJob($name, $hotel);
    $record = $level === 'hotel' ? $hotel : $account;

    expect(sjPassesGate($job))->toBeTrue();

    spSuspend($record);
    expect(sjPassesGate($job))->toBeFalse();

    spReactivate($record);
    expect(sjPassesGate($job))->toBeTrue();
})->with('suspension hotel jobs')->with(['hotel', 'account']);

it('puts the gate first on every hotel job', function (string $name) {
    Queue::fake();
    [, $hotel] = spAccount();

    expect(sjHotelJob($name, $hotel)->middleware()[0])->toBeInstanceOf(SkipIfHotelSuspended::class);
})->with('suspension hotel jobs');

it('runs a job with no hotel, or whose record is gone', function () {
    Queue::fake();
    $global = KnowledgeBaseArticle::create(['hotel_id' => null, 'title' => 'Greeting', 'content' => 'Greet guests.', 'status' => 'published']);

    expect(sjPassesGate(new SyncKnowledgeChunksJob($global)))->toBeTrue()
        ->and(sjPassesGate(new IndexKnowledgeDocumentJob((string) Str::uuid())))->toBeTrue();
});

it('ends a blocked import as failed, with no failed job and no alert', function (string $class) {
    Event::fake([JobFailed::class]);
    [, $hotel] = spAccount();
    $import = ReservationImport::create(['hotel_id' => $hotel->id, 'file_name' => 'r.csv', 'status' => ImportStatus::RUNNING, 'date_order' => 'day_first']);
    spSuspend($hotel);

    Bus::dispatchSync(new $class($import->id));

    expect(ReservationImport::withoutGlobalScope('hotel')->find($import->id)->status)->toBe(ImportStatus::FAILED)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Event::assertNotDispatched(JobFailed::class);
})->with([PrepareReservationImportJob::class, WriteReservationImportJob::class]);

it('treats a blocked import as a deliberate stop, not a failure', function (string $class) {
    Log::spy();
    [, $hotel] = spAccount();
    $import = ReservationImport::create(['hotel_id' => $hotel->id, 'file_name' => 'r.csv', 'status' => ImportStatus::RUNNING, 'date_order' => 'day_first']);
    spSuspend($hotel);

    Bus::dispatchSync(new $class($import->id));

    Log::shouldNotHaveReceived('error');

    expect(ReservationImport::withoutGlobalScope('hotel')->find($import->id)->failure_reason)
        ->toContain('suspended')
        ->not->toContain('unexpectedly');
})->with([PrepareReservationImportJob::class, WriteReservationImportJob::class]);

it('ends a blocked voucher email as failed, and sends nothing', function () {
    Mail::fake();
    [, $hotel] = spAccount();
    $request = sjVoucherRequest($hotel);
    spSuspend($hotel);

    Bus::dispatchSync(new SendReservationVoucherEmailJob($request->id));

    expect(VoucherEmailRequest::withoutGlobalScope('hotel')->find($request->id)->status->value)->toBe('failed')
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Mail::assertNothingOutgoing();
});

it('leaves no failed job when the handler itself throws, and reports the error', function () {
    Exceptions::fake();
    [, $hotel] = spAccount();
    spSuspend($hotel);

    $job = new class($hotel->id) implements HasHotel
    {
        public function __construct(public string $id) {}

        public function hotelId(): ?string
        {
            return $this->id;
        }

        public function failed(?Throwable $exception): void
        {
            throw new RuntimeException('Cleanup broke');
        }
    };

    expect(sjPassesGate($job))->toBeFalse()
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Cleanup broke');
});

it('hands the handler the suspension, and logs the skip', function () {
    Log::spy();
    [, $hotel] = spAccount();
    spSuspend($hotel);
    $seen = new ArrayObject;

    $job = new class($hotel->id, $seen) implements HasHotel
    {
        public function __construct(public string $id, public ArrayObject $seen) {}

        public function hotelId(): ?string
        {
            return $this->id;
        }

        public function failed(?Throwable $exception): void
        {
            $this->seen['exception'] = $exception;
        }
    };

    expect(sjPassesGate($job))->toBeFalse()
        ->and($seen['exception'])->toBeInstanceOf(HotelSuspended::class)
        ->and($seen['exception']->hotelId)->toBe($hotel->id);

    Log::shouldHaveReceived('info')->with('job.skipped_suspended', Mockery::on(fn (array $context) => $context['hotel_id'] === $hotel->id));
});

it('tags a blocked job for monitoring', function () {
    config(['sentry.dsn' => 'https://public@example.ingest.sentry.io/1']);
    [, $hotel] = spAccount();
    spSuspend($hotel);

    $hub = SentrySdk::getCurrentHub();
    $hub->pushScope();

    try {
        sjPassesGate(new ExtractGuestMemoriesJob($hotel->id, (string) Str::uuid(), (string) Str::uuid()));

        $tags = [];
        Sentry\configureScope(function (Scope $scope) use (&$tags) {
            $tags = $scope->applyToEvent(SentryEvent::createEvent())?->getTags() ?? [];
        });

        expect($tags['suspended'] ?? null)->toBe('true');
    } finally {
        $hub->popScope();
    }
});

/**
 * A guest with a proactive message waiting to go out, left scheduled.
 *
 * @return array{0: Guest, 1: Hotel}
 */
function sjScheduledProactive(): array
{
    rapPitchingOn();
    // 07:05 UTC is 10:05 in Riyadh, inside the first morning's window.
    test()->travelTo(Carbon::parse('2026-10-10 07:05:00', 'UTC'));

    $hotel = rapProactiveOn(rapHotel(), ['triggers' => ['mid_stay' => false, 'recommendation_approved' => false, 'upcoming_activity' => false]]);
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, abActivity($hotel, ['name' => 'Snorkeling', 'description' => 'Reef trip.']), 'approved');
    rapInbound($guest, now()->subHours(2));

    // Record the row without sending it.
    $queue = app('queue');
    Queue::fake();
    rapSweep();
    Queue::swap($queue);

    return [$guest, $hotel];
}

it('leaves a blocked proactive message scheduled, then sends it late if still valid', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest, $hotel] = sjScheduledProactive();
    $row = rapProactiveRows($guest)->sole();

    expect($row->status)->toBe(ProactiveMessageStatus::SCHEDULED);

    spSuspend($hotel);
    Bus::dispatchSync(new SendProactiveMessageJob($row->id));
    rapSweep();

    expect(rapProactiveRows($guest)->sole()->status)->toBe(ProactiveMessageStatus::SCHEDULED)
        ->and($whatsApp->sent)->toBe([]);

    spReactivate($hotel);
    rapSweep();

    expect(rapProactiveRows($guest)->sole()->status)->toBe(ProactiveMessageStatus::SENT)
        ->and($whatsApp->sent)->toHaveCount(1);
});

it('does not send a late proactive message past its validity', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest, $hotel] = sjScheduledProactive();
    $row = rapProactiveRows($guest)->sole();
    spSuspend($hotel);

    $this->travelTo($row->valid_until->copy()->addMinute());
    spReactivate($hotel);
    Bus::dispatchSync(new SendProactiveMessageJob($row->id));

    expect(rapProactiveRows($guest)->sole()->status)->not->toBe(ProactiveMessageStatus::SENT)
        ->and($whatsApp->sent)->toBe([]);
});

it('skips a suspended hotel in every job that loops over hotels', function (string $class, string $level) {
    Queue::fake();
    [$account, $suspended] = spAccount('stopped');
    [, $active] = spAccount('running');
    $suspended = rapProactiveOn($suspended);
    $active = rapProactiveOn($active);
    spSuspend($level === 'hotel' ? $suspended : $account);

    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    app()->call([new $class, 'handle']);

    expect($bindings)->toContain($active->id)->not->toContain($suspended->id);
})->with([
    'proactive' => EvaluateProactiveTriggersJob::class,
    'expiry' => ExpireRecommendationsJob::class,
    'outcomes' => MatchRecommendationOutcomesJob::class,
    'housekeeping' => StartHousekeepingDayJob::class,
])->with(['hotel', 'account']);

it('skips a suspended hotel when a looping job is asked about that hotel', function (string $job) {
    Queue::fake();
    [, $hotel] = spAccount();
    $hotel = rapProactiveOn($hotel);
    spSuspend($hotel);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app()->call([$job === 'outcomes' ? new MatchRecommendationOutcomesJob($hotel->id) : new EvaluateProactiveTriggersJob(hotelId: $hotel->id), 'handle']);

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('from "hotels"');
})->with(['outcomes', 'proactive']);

it('holds guest memory retries during a suspension, and resumes them after', function () {
    Queue::fake();
    [, $hotel] = spAccount();
    GuestMemoryExtraction::create([
        'hotel_id' => $hotel->id,
        'guest_id' => gsGuest($hotel)->id,
        'reservation_id' => reservationFor($hotel)->id,
        'status' => 'pending_retry',
        'attempts' => 1,
        'retry_at' => now()->subHour(),
    ]);
    spSuspend($hotel);

    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);
    Queue::assertNothingPushed();

    spReactivate($hotel);

    app()->call([new RetryGuestMemoryExtractionsJob, 'handle']);
    Queue::assertPushed(ExtractGuestMemoriesJob::class);
});

it('keeps a suspended hotel\'s deleted documents, and purges the rest', function () {
    Queue::fake();
    knFakeEmbeddings();
    Storage::fake((string) config('knowledge.disk'));
    [, $suspended] = spAccount('stopped');
    [, $active] = spAccount('running');
    [, $deletedHotel] = spAccount('gone');
    $kept = knDocument($suspended);
    $documents = [$kept, knDocument($active), knDocument($deletedHotel), knDocument(null)];

    foreach ($documents as $document) {
        $document->forceFill(['deleted_at' => now()->subDays((int) config('knowledge.purge_after_days') + 1)])->save();
    }

    spSuspend($suspended);
    $deletedHotel->delete();

    app()->call([new PurgeDeletedKnowledgeDocumentsJob, 'handle']);

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->pluck('id')->all())->toBe([$kept->id]);
});

it('finishes a knowledge rebuild, counting a suspended hotel\'s items as failed', function () {
    knFakeEmbeddings();
    [, $hotel] = knHotel();
    [, $other] = knHotel();
    knDocument($hotel, ['title' => 'Rules'], [['location' => 'Page 1', 'text' => 'Checkout is at 10.']]);
    knDocument($other, ['title' => 'Other'], [['location' => 'Page 1', 'text' => 'Checkout is at 11.']]);
    spSuspend($hotel);

    $response = knRequest($this, knSuperAdmin(), 'POST', '/api/superadmin/knowledge/rebuilds', ['scope' => 'all'])->assertStatus(202);

    expect(KnowledgeIndexRebuild::findOrFail($response->json('body.id')))
        ->failed->toBe(1)
        ->succeeded->toBe(1)
        ->finished_at->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
