<?php

use App\Enums\ActorKind;
use App\Models\Activity;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-055 FR-016, SC-004: every Admin AI write is in the audit trail as the
 * AI acting for the admin, with the tool and the conversation, also when no
 * one is signed in (a WhatsApp turn runs in a queue job). Reads are not
 * audited (FR-008).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

it('records an AI write as the AI acting for the admin, with tool and conversation, with no one signed in', function () {
    $s = aatSeed();
    Auth::logout();

    aatCall(aatTool($s['admin'], 'CreateGuestTool', 'conv-1'), ['phone_number' => '+44 7700 900123', 'first_name' => 'Audited'], 'call-1');

    $guest = Guest::withoutGlobalScope('hotel')->where('first_name', 'Audited')->firstOrFail();
    $event = EventLog::withoutGlobalScope('hotel')->where('subject_id', $guest->id)->where('event_type', 'guest.created')->firstOrFail();

    expect($event->actor_kind)->toBe(ActorKind::AI_AGENT)
        ->and($event->actor_id)->toBe($s['admin']->id)
        ->and($event->hotel_id)->toBe($s['hotel']->id)
        ->and($event->context['ai'])->toMatchArray([
            'agent' => 'admin_advisor',
            'tool' => 'CreateGuestTool',
            'tool_call_id' => 'call-1',
            'conversation_id' => 'conv-1',
        ]);
});

it('audits activities and knowledge articles the AI writes', function () {
    $s = aatSeed();

    aatCall(aatTool($s['admin'], 'CreateActivityTool', 'conv-2'), ['name' => 'Desert safari', 'price' => 80]);
    aatCall(aatTool($s['admin'], 'CreateKnowledgeArticleTool', 'conv-2'), ['title' => 'Shuttle', 'content' => 'Leaves at 9:00.', 'draft' => true]);

    $article = KnowledgeBaseArticle::withoutGlobalScope('hotel')->where('title', 'Shuttle')->firstOrFail();
    $activity = Activity::withoutGlobalScope('hotel')->where('name', 'Desert safari')->firstOrFail();

    foreach (['activity.created' => $activity->id, 'knowledge_base_article.created' => $article->id] as $type => $subjectId) {
        $event = EventLog::withoutGlobalScope('hotel')->where('event_type', $type)->where('subject_id', $subjectId)->firstOrFail();

        expect($event->actor_kind)->toBe(ActorKind::AI_AGENT, $type)
            ->and($event->actor_id)->toBe($s['admin']->id, $type)
            ->and($event->context['ai']['conversation_id'] ?? null)->toBe('conv-2', $type);
    }
});

it('writes nothing to the audit trail for a read', function () {
    $s = aatSeed();
    $before = EventLog::withoutGlobalScope('hotel')->count();

    foreach (['GetRoomsTool', 'GetGuestTool', 'GetReservationTool', 'GetBookingsTool', 'GetStaffTool'] as $name) {
        aatCall(aatTool($s['admin'], $name), aatArgs($name, $s));
    }

    expect(EventLog::withoutGlobalScope('hotel')->count())->toBe($before);
});
