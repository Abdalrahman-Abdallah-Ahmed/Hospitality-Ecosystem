<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\MeterFeature;
use App\Http\Requests\AiAdvisorChatRequest;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use Laravel\Ai\Models\Conversation;

class AiAdvisorController extends Controller
{
    /**
     * Send a message to the admin advisor and get a reply.
     */
    public function chat(AiAdvisorChatRequest $request, MeteringService $metering)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return apiResponse('This action is unauthorized.', 403);
        }

        if (! $user->hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $conversationId = $request->validated('conversation_id');

        $agent = AdminAdvisorAgent::make(user: $user);

        if ($conversationId) {
            $ownsConversation = Conversation::query()
                ->where('id', $conversationId)
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user))
                ->exists();

            if (! $ownsConversation) {
                return apiResponse('Conversation not found.', 404);
            }

            $agent->continue($conversationId, $user);
        } else {
            $agent->forUser($user);
        }

        // A logged-in human asked for this, so its cost is bounded by staff
        // behaviour rather than by whoever has the hotel's number.
        $response = AiCostContext::for(
            kind: AiTriggerKind::STAFF_REQUEST,
            hotel: $user->hotel,
            trigger: $user,
            callback: fn () => $agent->prompt($request->validated('message')),
        );

        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $user->hotel,
            feature: MeterFeature::AI_MESSAGES,
            source: $user,
            metadata: ['channel' => 'advisor_chat'],
            actorKind: ActorKind::AI_AGENT,
        ));

        return apiResponse('Advisor replied successfully.', 200, [
            'conversation_id' => $agent->currentConversation(),
            'reply' => $response->text,
        ]);
    }
}
