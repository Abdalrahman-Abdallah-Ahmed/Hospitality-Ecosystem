<?php

namespace App\Http\Controllers;

use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\MeterFeature;
use App\Exceptions\PendingConfirmationChangedException;
use App\Http\Requests\AiAdvisorChatRequest;
use App\Services\Ai\AdvisorTurn;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Models\Conversation;

class AiAdvisorController extends Controller
{
    /**
     * Send a message to the admin advisor and get a reply — or answer the
     * actions it is waiting to have confirmed (contracts/advisor-chat-api.md).
     */
    public function chat(AiAdvisorChatRequest $request, MeteringService $metering, AdvisorTurn $turn)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return apiResponse('This action is unauthorized.', 403);
        }

        if (! $user->hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $conversationId = $request->validated('conversation_id');

        if ($conversationId) {
            $ownsConversation = Conversation::query()
                ->where('id', $conversationId)
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user))
                ->exists();

            if (! $ownsConversation) {
                return apiResponse('Conversation not found.', 404);
            }
        }

        // A logged-in human asked for this, so its cost is bounded by staff
        // behaviour rather than by whoever has the hotel's number.
        try {
            $result = AiCostContext::for(
                kind: AiTriggerKind::STAFF_REQUEST,
                hotel: $user->hotel,
                trigger: $user,
                callback: fn () => $turn->handle(
                    $user,
                    $conversationId,
                    $request->validated('message'),
                    $request->validated('decision'),
                    $request->validated('pending_ids'),
                ),
            );
        } catch (PendingConfirmationChangedException $e) {
            return apiResponse($e->getMessage(), 409, ['pending_confirmation' => $e->pendingConfirmation]);
        } catch (ApprovalMismatchException) {
            return apiResponse('The actions waiting for confirmation have changed. Review them again.', 409, [
                'pending_confirmation' => null,
            ]);
        }

        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $user->hotel,
            feature: MeterFeature::AI_MESSAGES,
            source: $user,
            metadata: ['channel' => 'advisor_chat'],
            actorKind: ActorKind::AI_AGENT,
        ));

        return apiResponse('Advisor replied successfully.', 200, [
            'conversation_id' => $result->conversationId,
            'reply' => $result->reply,
            'pending_confirmation' => $result->pendingConfirmation,
        ]);
    }
}
