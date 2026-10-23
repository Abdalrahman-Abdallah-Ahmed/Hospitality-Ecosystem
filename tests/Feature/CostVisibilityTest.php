<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\InsightsAgent;
use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Enums\AiAgentName;
use App\Enums\InboundMessageStatus;
use App\Enums\Permission;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\ActivityCategory;
use App\Models\AiActivity;
use App\Models\AiInsights;
use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\ReservationImport;
use App\Models\ReservationImportRow;
use App\Models\StaffRole;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

/*
| Provider cost, model prices, margin, contract value, the model and the
| provider never reach a hotel user (SPEC-091 FR-012, SC-001). Checked three
| ways against seeded sentinel values: who may touch cost at all (a scan of
| app/), every hotel-facing GET route, and every read tool the Admin AI and
| the insights agent hold — plus the messages sent when the ceiling stops AI.
*/

uses(RefreshDatabase::class);

const CV_SENTINELS = ['sentinel-model-x9', 'sentinel-provider', '987.65', '4242.42'];

const CV_DENIED_KEYS = [
    'cost', 'cost_usd', 'cost_eur', 'cost_per_call_eur',
    'price_per_million', 'input_price_per_million', 'output_price_per_million', 'cached_input_price_per_million',
    'margin', 'gross_margin', 'gross_margin_pct',
    'contract_value', 'contract_value_monthly',
    'model', 'provider',
];

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    // Far below the sentinel spend, so every path that meets the ceiling runs.
    config(['ai_cost.daily_ceiling_usd' => 10.00]);
    AiCostContext::reset();
});

afterEach(function () {
    AiCostContext::reset();
});

/**
 * One hotel with a record behind every hotel-facing route, an account with a
 * contract value, and AI usage priced with the sentinels.
 *
 * @return array<string, mixed>
 */
function cvSeed(): array
{
    Embeddings::fake();

    [$hotel, $type, [$room]] = fdHotel(1);
    $admin = $hotel->owner;

    $hotel->hotelGroup->update(['contract_value_monthly' => 4242.42, 'contract_currency' => 'EUR']);

    AiModelPrice::create([
        'provider' => 'sentinel-provider',
        'model' => 'sentinel-model-x9',
        'input_price_per_million' => 987.654321,
        'output_price_per_million' => 987.654321,
        'currency' => 'USD',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);

    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $guest = Guest::findOrFail($reservation->guest_id);
    $activity = abActivity($hotel);
    $booking = abBook($hotel, $activity, now()->addDay()->toDateString());
    $category = ActivityCategory::create(['hotel_id' => $hotel->id, 'name' => 'Water sports']);
    $team = Team::query()->where('hotel_id', $hotel->id)->firstOrFail();
    $taskCategory = TaskCategory::query()->where('hotel_id', $hotel->id)->firstOrFail();
    $task = Task::create(['hotel_id' => $hotel->id, 'title' => 'Fix the lamp', 'created_by' => 'manual', 'status' => 'pending']);
    $article = KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Breakfast', 'content' => 'From 7 to 10.', 'status' => 'published']);
    $policy = HotelPolicy::create(['hotel_id' => $hotel->id, 'title' => 'Pets', 'content' => 'Small pets welcome.']);
    $document = KnowledgeDocument::create([
        'hotel_id' => $hotel->id,
        'title' => 'House Rules',
        'original_filename' => 'rules.pdf',
        'path' => 'knowledge/'.$hotel->id.'/doc/rules.pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'content_hash' => hash('sha256', uniqid()),
    ]);
    $document->forceFill([
        'status' => 'failed',
        'failure_code' => 'usage_limit_reached',
        'error' => 'The daily AI usage limit was reached; try again tomorrow.',
    ])->save();
    $recommendation = Recommendation::create(['hotel_id' => $hotel->id, 'activity_id' => $activity->id, 'reservation_id' => $reservation->id]);
    $transaction = Transaction::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'item_name' => 'Dive trip',
        'line_total' => 120,
        'currency' => 'USD',
        'transacted_at' => now(),
        'business_date' => now()->toDateString(),
        'source_system' => 'import',
        'external_reference' => 'ext-'.uniqid(),
    ]);
    $proactive = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'trigger' => 'first_morning',
        'event_key' => 'first_morning:'.uniqid(),
        'status' => 'scheduled',
        'due_at' => now(),
        'valid_until' => now()->addHours(3),
    ]))->save();
    $conversation = cmaGuestConversation($guest, [['role' => 'user', 'content' => 'Hello'], ['role' => 'assistant', 'content' => 'Welcome!']]);
    cmaMemory($guest);
    AiInsights::create(['hotel_id' => $hotel->id, 'title' => 'Busy weekend', 'description' => 'Plan staff.', 'category' => 'general', 'insight_type' => 'general']);

    $employee = fdEmployee($hotel, Permission::cases());

    // Last, after every record that indexes knowledge: past this point the
    // account is over its daily ceiling.
    $runId = (string) Str::uuid7();

    AiUsageLog::create([
        'hotel_group_id' => $hotel->hotel_group_id,
        'hotel_id' => $hotel->id,
        'agent' => 'GuestConciergeAgent',
        'provider' => 'sentinel-provider',
        'model' => 'sentinel-model-x9',
        'operation' => 'chat',
        'input_tokens' => 1200,
        'output_tokens' => 80,
        'cost_usd' => 987.654321,
        'trigger_kind' => 'guest_message',
        'capability' => AiAgentName::CONCIERGE->value,
        'ai_run_id' => $runId,
        'occurred_at' => now(),
    ]);

    AiActivity::create([
        'id' => $runId,
        'ai_run_id' => $runId,
        'hotel_id' => $hotel->id,
        'category' => 'ai_run',
        'agent' => 'concierge',
        'action' => 'run.completed',
        'result' => 'succeeded',
        'trigger_kind' => 'guest_message',
        'usage' => ['requests' => 1, 'input_tokens' => 1200, 'output_tokens' => 80, 'cached_tokens' => 0],
        'occurred_at' => now(),
    ]);

    $import = ReservationImport::create([
        'hotel_id' => $hotel->id,
        'uploaded_by' => $admin->id,
        'file_name' => 'reservations.csv',
        'status' => 'completed',
        'date_order' => 'day_first',
        'total_rows' => 1,
        'invalid' => 1,
    ]);
    ReservationImportRow::create([
        'hotel_id' => $hotel->id,
        'reservation_import_id' => $import->id,
        'row_number' => 2,
        'outcome' => 'invalid',
        'stage' => 'validate',
        'reason' => 'arrival_date is empty.',
    ]);

    return [
        'hotel' => $hotel->fresh(),
        'admin' => $admin,
        'employee' => $employee,
        'guest' => $guest,
        'reservation' => $reservation,
        // Route parameter name => the id it resolves to.
        'params' => [
            'activity' => $activity->id,
            'activity_category' => $category->id,
            'booking' => $booking->id,
            'conversation' => $conversation,
            'guest' => $guest->id,
            'hotel' => $hotel->id,
            'knowledge_base_article' => $article->id,
            'proactiveMessage' => $proactive->id,
            'recommendation' => $recommendation->id,
            'reservation' => $reservation->id,
            'reservationImport' => $import->id,
            'room' => $room->id,
            'room_type' => $type->id,
            'staff_role' => StaffRole::query()->where('hotel_id', $hotel->id)->value('id'),
            'stay' => $stay->id,
            'task' => $task->id,
            'task_category' => $taskCategory->id,
            'team' => $team->id,
            'transaction' => $transaction->id,
            'user' => $employee->id,
        ],
        // URIs whose parameters are not one record, or that need a query to
        // return data rather than a validation error: filled in directly.
        'uris' => [
            'api/availability' => 'api/availability?arrival_date='.now($hotel->timezone)->toDateString().'&departure_date='.now($hotel->timezone)->addDays(2)->toDateString(),
            'api/activity/{activity}/availability' => "api/activity/{$activity->id}/availability?from=".now($hotel->timezone)->toDateString(),
            'api/history/{type}/{id}' => "api/history/reservation/{$reservation->id}",
            'api/knowledge-documents/{id}' => "api/knowledge-documents/{$document->id}",
            'api/knowledge-documents/{id}/download' => "api/knowledge-documents/{$document->id}/download",
            'api/knowledge-documents/{id}/text' => "api/knowledge-documents/{$document->id}/text",
        ],
    ];
}

/**
 * The sentinel values and denied keys found in a body, as readable strings.
 *
 * @return list<string>
 */
function cvLeaks(string $body): array
{
    $leaks = [];
    $lower = strtolower($body);

    foreach (CV_SENTINELS as $sentinel) {
        if (str_contains($lower, $sentinel)) {
            $leaks[] = "value {$sentinel}";
        }
    }

    $decoded = json_decode($body, true);

    if (is_array($decoded)) {
        $keys = [];
        $collect = function (array $node) use (&$collect, &$keys) {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keys[$key] = true;
                }
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($decoded);

        foreach (CV_DENIED_KEYS as $denied) {
            if (isset($keys[$denied])) {
                $leaks[] = "key {$denied}";
            }
        }
    }

    return $leaks;
}

it('lets only the cost-owning classes touch cost data', function () {
    // The single list of who may reference cost (tasks.md T010(a)). Anything
    // else that reads cost is a leak waiting for a response to carry it.
    $allowed = [
        'app/Services/AiCost/',
        'app/Jobs/AiCost/',                         // log-only margin alerts
        'app/Support/Ai/AiRun.php',
        'app/Support/Ai/AiCostContext.php',
        'app/Support/Audit/EventLogger.php',        // excludes AiUsageLog from auditing
        'app/Listeners/RecordAiUsage.php',
        'app/Services/Metering/AiUsageSummary.php', // counts and tokens only
        'app/Http/Controllers/Admin/',              // super-admin stack
        'app/Http/Controllers/AiActivityController.php', // run cost, super admins only
        'app/Http/Resources/AiActivityResource.php',
        'app/Models/',
        'app/Exceptions/AiSpendCeilingExceededException.php',
    ];

    $patterns = ['AiUsageLog', 'AiModelPrice', 'cost_usd', 'price_per_million', 'contract_value', 'gross_margin'];
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/'.str_replace('\\', '/', Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR));

        if (collect($allowed)->contains(fn (string $prefix) => str_starts_with($relative, $prefix))) {
            continue;
        }

        // Comments may explain the rule; only code is held to it.
        $code = collect(token_get_all(file_get_contents($file->getPathname())))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        foreach ($patterns as $pattern) {
            if (str_contains($code, $pattern)) {
                $offenders[] = "{$relative} references {$pattern}";
            }
        }
    }

    // Notifications are built from hotel data only; none may read cost.
    expect(collect($allowed)->contains(fn ($prefix) => str_starts_with('app/Notifications/', $prefix)))->toBeFalse();
    expect($offenders)->toBe([]);
});

it('never shows cost, price, margin, model or provider on any hotel-facing route', function () {
    $seed = cvSeed();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'api/') && ! str_starts_with($uri, 'api/admin'))
        ->unique()
        ->values();

    $unresolved = [];
    $leaks = [];
    $checked = 0;

    foreach ($routes as $uri) {
        $path = $seed['uris'][$uri] ?? preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($seed, &$unresolved, $uri) {
            if (! isset($seed['params'][$match[1]])) {
                $unresolved[] = "{$uri} ({$match[1]})";

                return $match[0];
            }

            return $seed['params'][$match[1]];
        }, $uri);

        if (str_contains($path, '{')) {
            continue;
        }

        foreach (['admin' => $seed['admin'], 'employee' => $seed['employee']] as $who => $user) {
            $body = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
                ->getJson('/'.$path)
                ->getContent();

            foreach (cvLeaks($body) as $leak) {
                $leaks[] = "GET /{$path} as {$who}: {$leak}";
            }

            $checked++;
        }
    }

    // A new route whose parameter this test cannot fill must be added to the
    // seed, never skipped silently.
    expect($unresolved)->toBe([])
        ->and($leaks)->toBe([])
        ->and($checked)->toBeGreaterThan(100);
});

it('never shows cost, model or provider in what the Admin AI and the insights agent can read', function () {
    $seed = cvSeed();
    Embeddings::fake();

    $hotel = $seed['hotel'];
    $today = now($hotel->timezone)->toDateString();

    $args = [
        'KnowledgeSearchTool' => [['query' => 'breakfast']],
        'GetGuestTool' => [['guest' => $seed['guest']->id]],
        'GetReservationTool' => [['code' => $seed['reservation']->reservation_id]],
        'GetAvailabilityTool' => [['arrival_date' => $today, 'departure_date' => now($hotel->timezone)->addDays(2)->toDateString()]],
        'GetStaysTool' => [['list' => 'in_house']],
        'GetReportTool' => array_map(fn (string $report) => ['report' => $report, 'from' => now()->startOfMonth()->toDateString(), 'to' => $today], ['dashboard', 'occupancy', 'conversion', 'insights', 'usage']),
    ];

    $tools = collect(AdminToolset::for($seed['admin']))
        ->filter(fn (GuardedTool $tool) => $tool->kind() === GuardedTool::READ)
        ->concat(collect((new InsightsAgent($seed['admin']))->tools()))
        ->values();

    $leaks = [];

    foreach ($tools as $tool) {
        $name = class_basename($tool instanceof GuardedTool ? $tool->inner() : $tool);

        foreach ($args[$name] ?? [[]] as $arguments) {
            $output = (string) $tool->handle(new Request($arguments));

            foreach (cvLeaks($output) as $leak) {
                $leaks[] = "{$name}: {$leak}";
            }
        }
    }

    expect($tools->count())->toBeGreaterThan(10)
        ->and($leaks)->toBe([]);
});

it('tells the admin the assistant is unavailable at the ceiling, without the amounts', function () {
    $seed = cvSeed();
    AdminAdvisorAgent::fake(['should not be asked'])->preventStrayPrompts();

    $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($seed['admin'], 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'How busy are we today?'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The AI assistant is temporarily unavailable. Try again later.');

    expect(cvLeaks($response->getContent()))->toBe([])
        ->and($response->getContent())->not->toContain('$');
});

it('tells the guest the hotel is unavailable at the ceiling, without the amounts', function () {
    $seed = cvSeed();
    $guest = $seed['guest'];
    $guest->update(['phone_number' => '+201000000777']);

    $sent = [];
    $this->mock(WhatsAppMessageService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('send')->andReturnUsing(function (...$args) use (&$sent) {
            $sent[] = implode(' ', array_filter($args, 'is_string'));

            return true;
        });
    });

    (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => '+201000000777', 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: '+201000000777',
        messageText: 'What time is breakfast?',
        senderType: SenderType::GUEST,
        sender: $guest->fresh(),
        hotel: $seed['hotel'],
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    $reply = implode("\n", $sent);

    expect($reply)->toContain(ProcessInboundWhatsAppMessageJob::UNAVAILABLE_REPLY)
        ->and(cvLeaks($reply))->toBe([])
        ->and($reply)->not->toContain('$');
});

it('still shows super admins the cost and the model', function () {
    $seed = cvSeed();
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $cost = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertOk()
        ->getContent();

    expect($cost)->toContain('sentinel-model-x9')->toContain('987.65');

    $activity = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->getJson('/api/ai-activity?hotel_id='.$seed['hotel']->id)
        ->assertOk()
        ->getContent();

    expect($activity)->toContain('cost_usd');
});

it('redacts ceiling amounts already stored on failed knowledge documents', function () {
    [$hotel] = fdHotel(1);

    $document = KnowledgeDocument::create([
        'hotel_id' => $hotel->id,
        'title' => 'Old failure',
        'original_filename' => 'old.pdf',
        'path' => 'knowledge/'.$hotel->id.'/doc/old.pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'content_hash' => hash('sha256', uniqid()),
    ]);
    $document->forceFill([
        'status' => 'failed',
        'failure_code' => 'usage_limit_reached',
        'error' => 'Account [Grand] has spent $12.0000 on AI today, at or past its daily ceiling of $10.00.',
    ])->save();

    // Re-queued after the ceiling stop: the re-index cleared the failure
    // code when it started, but the old message is still stored.
    $requeued = $document->replicate(['content_hash'])->fill(['title' => 'Re-queued', 'content_hash' => hash('sha256', uniqid())]);
    $requeued->forceFill([
        'status' => 'uploaded',
        'failure_code' => null,
        'error' => 'Account [Grand] has spent $12.0000 on AI today, at or past its daily ceiling of $10.00.',
    ])->save();

    $migration = require database_path('migrations/2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents.php');
    $migration->up();
    $migration->up();

    expect($document->fresh()->error)->toBe('The daily AI usage limit was reached; try again tomorrow.')
        ->and($requeued->fresh()->error)->toBe('The daily AI usage limit was reached; try again tomorrow.');
});
