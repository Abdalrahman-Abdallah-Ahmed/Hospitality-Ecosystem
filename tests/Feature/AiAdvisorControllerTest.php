<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Models\Conversation;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Embeddings::fake();
});

function aiAdvisorApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithOwnedHotelForAdvisor(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

it('rejects an unauthenticated chat request', function () {
    $this->withHeaders(aiAdvisorApiHeaders())->postJson('/api/ai-advisor/chat', ['message' => 'Hello'])
        ->assertStatus(401);
});

it('rejects a worker from chatting with the advisor', function () {
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(aiAdvisorApiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Hello'])
        ->assertStatus(403);
});

it('rejects a super admin from chatting with the advisor, since it has no hotel', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(aiAdvisorApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Hello'])
        ->assertStatus(403);
});

it('starts a new conversation and returns the reply', function () {
    [$admin] = adminWithOwnedHotelForAdvisor();
    AdminAdvisorAgent::fake(['Here is some advice about your hotel.']);

    $response = $this->withHeaders(aiAdvisorApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'What should I do about late checkouts?']);

    $response->assertOk()
        ->assertJsonPath('body.reply', 'Here is some advice about your hotel.')
        ->assertJsonPath('body.conversation_id', fn ($id) => filled($id));

    expect(Conversation::where('participant_id', $admin->id)->count())->toBe(1);
});

it('continues an existing conversation belonging to the caller', function () {
    [$admin] = adminWithOwnedHotelForAdvisor();
    AdminAdvisorAgent::fake(['First reply.']);

    $first = $this->withHeaders(aiAdvisorApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'First question']);

    $conversationId = $first->json('body.conversation_id');

    AdminAdvisorAgent::fake(['Second reply.']);

    $second = $this->withHeaders(aiAdvisorApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/ai-advisor/chat', [
            'message' => 'Follow-up question',
            'conversation_id' => $conversationId,
        ]);

    $second->assertOk()
        ->assertJsonPath('body.reply', 'Second reply.')
        ->assertJsonPath('body.conversation_id', $conversationId);

    expect(Conversation::where('participant_id', $admin->id)->count())->toBe(1);
});

it('rejects continuing a conversation that belongs to a different admin', function () {
    [$owner] = adminWithOwnedHotelForAdvisor();
    AdminAdvisorAgent::fake(['Private reply.']);

    $owned = $this->withHeaders(aiAdvisorApiHeaders())->actingAs($owner, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Something private']);

    $conversationId = $owned->json('body.conversation_id');

    [$stranger] = adminWithOwnedHotelForAdvisor();

    $this->withHeaders(aiAdvisorApiHeaders())->actingAs($stranger, 'sanctum')
        ->postJson('/api/ai-advisor/chat', [
            'message' => 'Let me read your history',
            'conversation_id' => $conversationId,
        ])
        ->assertStatus(404);
});
