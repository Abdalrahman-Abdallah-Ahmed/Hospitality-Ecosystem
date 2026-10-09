<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\InboundMessageStatus;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Notifications\AiTaskCreatedNotification;
use App\Services\Metering\MeteringService;
use App\Services\Tasks\TaskCommands;
use App\Services\WhatsAppMessageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

/*
 * Regressions for the SPEC-055 code review: one confirmation per
 * conversation at a time, notifications only after commit, removing rooms
 * needs confirmation, room types named twice add up, a line named twice is
 * refused, exact staff names win, programming errors are not refusals,
 * "today" in reports is the hotel's, and WhatsApp has its own conversation.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

it('lets one turn at a time answer a conversation, so a double confirmation cannot run twice', function () {
    $s = aatSeed();
    AdminAdvisorAgent::fake([new ToolCall('call_1', 'CancelReservationTool', ['code' => $s['code']]), 'Done.']);
    $conversation = aatChat($this, $s, ['message' => 'Cancel '.$s['code']])->json('body.conversation_id');

    // Another request is still answering this conversation.
    $held = Cache::lock('advisor-turn:'.$conversation, 180);
    $held->get();
    Sleep::fake(syncWithCarbon: true);

    aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'confirm'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The previous message in this conversation is still being answered. Try again in a moment.')
        // The frontend tells this 409 from "have changed" by a null body.
        ->assertJsonPath('body', null);

    AdminAdvisorAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->approvalDecisions !== null);
    $held->release();
});

it('tells staff about a task only once its transaction has committed', function () {
    Notification::fake();
    $s = aatSeed();

    try {
        DB::transaction(function () use ($s) {
            app(TaskCommands::class)->create($s['hotel'], ['title' => 'Rolled back', 'created_by' => 'ai', 'created_by_user_id' => $s['admin']->id], createdByAi: true);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();

    app(TaskCommands::class)->create($s['hotel'], ['title' => 'Kept', 'created_by' => 'ai', 'created_by_user_id' => $s['admin']->id], createdByAi: true);

    Notification::assertSentTo($s['admin'], AiTaskCreatedNotification::class);
});

it('asks for confirmation before a room list removes rooms, but not before adding one', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'UpdateReservationTool');

    avType($s['hotel'], 'Suite');
    $replacing = $tool->shouldRequestApproval(new Request(['code' => $s['code'], 'rooms' => [['room_type' => 'Suite']]]));
    $adding = $tool->shouldRequestApproval(new Request(['code' => $s['code'], 'rooms' => [['room_type' => 'Deluxe'], ['room_type' => 'Suite']]]));

    expect($replacing?->reason)->toBe("Change reservation {$s['code']}, removing these rooms from it: Deluxe unassigned.")
        ->and($adding)->toBeNull();
});

it('adds up a room type named twice instead of keeping the same room twice', function () {
    $s = aatSeed();

    aatCall(aatTool($s['admin'], 'UpdateReservationTool'), ['code' => $s['code'], 'rooms' => [['room_type' => 'Deluxe'], ['room_type' => 'Deluxe']]]);

    expect($s['reservation']->reservationRooms()->active()->count())->toBe(2);
});

it('refuses the same room line named twice instead of reporting both rooms', function () {
    $s = aatSeed();
    $line = $s['reservation']->reservationRooms()->first();

    $result = aatCall(aatTool($s['admin'], 'AssignRoomsTool'), ['code' => $s['code'], 'assignments' => [
        ['line_id' => $line->id, 'room_number' => '101'],
        ['line_id' => $line->id, 'room_number' => '102'],
    ]]);

    expect($result)->toContain('named twice')
        ->and($line->fresh()->room_id)->toBeNull();
});

it('picks the staff member whose name matches exactly over others containing it', function () {
    $s = aatSeed();
    $ali = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $s['hotel']->id, 'name' => 'Ali']);
    User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $s['hotel']->id, 'name' => 'Alice']);

    aatCall(aatTool($s['admin'], 'UpdateTaskTool'), ['task_id' => $s['task']->id, 'assignee' => 'ali']);

    expect($s['task']->fresh()->assigned_to_user_id)->toBe($ali->id);
});

it('lets a programming error through instead of calling it a refusal', function () {
    $tool = new class(aatHotel()[0])
    {
        use AdminToolSupport;

        public function __construct(private readonly Hotel $hotel) {}

        public function run(Closure $operation): mixed
        {
            return $this->attempt($operation);
        }
    };

    expect($tool->run(fn () => throw new RuntimeException('That booking can no longer be changed.')))
        ->toBe('Not done: That booking can no longer be changed. Nothing was changed.');

    expect(fn () => $tool->run(fn () => throw new ModelNotFoundException('lost')))->toThrow(ModelNotFoundException::class);
});

it('reports the hotel\'s today on the dashboard, not the server\'s', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
    $s = aatSeed('Alpha', 'Asia/Dubai');

    $dashboard = aatCall(aatTool($s['admin'], 'GetReportTool'), ['report' => 'dashboard']);
    $occupancy = aatCall(aatTool($s['admin'], 'GetReportTool'), ['report' => 'occupancy']);

    expect($dashboard['arrivals_today'])->toBe([$s['code']])
        ->and($dashboard['occupancy']['date'])->toBe('2026-10-10')
        ->and($occupancy['nights'][0]['date'])->toBe('2026-10-10');

    Carbon::setTestNow();
});

it('never lets a WhatsApp "yes" confirm actions waiting in the web chat', function () {
    $s = aatSeed();
    $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldReceive('send'));

    AdminAdvisorAgent::fake([new ToolCall('call_web', 'CancelReservationTool', ['code' => $s['code']]), 'Yes to what?']);
    aatChat($this, $s, ['message' => 'Cancel '.$s['code']])->assertOk();

    (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => '+201000000777', 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: '+201000000777',
        messageText: 'yes',
        senderType: SenderType::ADMIN,
        sender: $s['admin'],
        hotel: $s['hotel'],
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    AdminAdvisorAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->approvalDecisions !== null);
});

it('serves the audit history of activities and knowledge articles, to their own hotel only', function (string $slug, string $key, string $field) {
    $s = aatSeed();
    $other = aatSeed('Other');
    $s[$key]->update([$field => 'Changed']);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($s['admin'], 'sanctum')
        ->getJson("/api/history/{$slug}/{$s[$key]->id}")
        ->assertOk()
        ->assertJsonPath('body.meta.total', 2); // created + updated

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($other['admin'], 'sanctum')
        ->getJson("/api/history/{$slug}/{$s[$key]->id}")
        ->assertNotFound();
})->with([
    'activity' => ['activity', 'activity', 'name'],
    'knowledge base article' => ['knowledge-base-article', 'article', 'title'],
]);

it('starts the remembered history at a user turn, never at a half-turn the window cut into', function () {
    $s = aatSeed();
    $conversation = app(ConversationStore::class)->storeConversation($s['admin']->getMorphClass(), $s['admin']->id, 'Window');
    $call = ['id' => 'c1', 'name' => 'GetRoomsTool', 'arguments' => [], 'result_id' => 'c1'];
    $result = ['id' => 'c1', 'name' => 'GetRoomsTool', 'arguments' => [], 'result' => '{}', 'result_id' => 'c1'];

    // u, a(tool), u, a(tool), …: one row more than the agent's window, so the
    // window begins with a tool-calling assistant turn, the shape Gemini
    // refuses. Sized from the agent, so changing its window keeps this valid.
    $agent = AdminAdvisorAgent::make(user: $s['admin']);
    $window = (fn () => $this->maxConversationMessages())->call($agent);

    foreach (range(0, $window) as $i) {
        $assistant = $i % 2 === 1;

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation,
            'participant_type' => $s['admin']->getMorphClass(),
            'participant_id' => $s['admin']->id,
            'agent' => AdminAdvisorAgent::class,
            'role' => $assistant ? 'assistant' : 'user',
            'content' => "message {$i}",
            'attachments' => '[]',
            'tool_calls' => $assistant ? json_encode([$call]) : '[]',
            'tool_results' => $assistant ? json_encode([$result]) : '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $messages = collect($agent->continue($conversation, $s['admin'])->messages());

    expect($messages->first()->role)->toBe(MessageRole::User)
        ->and($messages->first()->content)->toBe('message 2');
});
