<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Http\Requests\AiAdvisorChatRequest;
use Laravel\Ai\Models\Conversation;

class AiAdvisorController extends Controller
{
    /**
     * Send a message to the admin advisor and get a reply.
     */
    public function chat(AiAdvisorChatRequest $request)
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

        $response = $agent->prompt($request->validated('message'));

        return apiResponse('Advisor replied successfully.', 200, [
            'conversation_id' => $agent->currentConversation(),
            'reply' => $response->text,
        ]);
    }
}
