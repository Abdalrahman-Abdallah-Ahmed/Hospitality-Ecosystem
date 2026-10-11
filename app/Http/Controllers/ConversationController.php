<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationIndexRequest;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Hotel;
use App\Services\Guests\ConversationQuery;
use App\Support\Audit\EventLogger;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Models\Conversation;

/**
 * What guests and the Concierge said to each other, read-only (SPEC-082).
 * The dashboard is the only place guest message text is shown: the Admin AI
 * has no tool for it (FR-033).
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationQuery $conversations,
    ) {}

    public function index(ConversationIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $hotel = $this->hotel($request);

        $page = $this->conversations->list(
            $hotel,
            $request->only(['guest_id', 'last_active_from', 'last_active_to']),
            $request->integer('per_page', 25),
        );

        return apiResponse('Conversations fetched successfully.', 200, ConversationResource::collection($page));
    }

    public function show(ConversationIndexRequest $request, string $conversation): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $hotel = $this->hotel($request);
        $found = $this->conversations->find($hotel, $conversation) ?? abort(404, 'Conversation not found.');

        // Opening a guest's conversation is reading protected guest data, so
        // it is recorded; turning its pages is the same opening (FR-029).
        if ($request->integer('page', 1) === 1) {
            EventLogger::record($found->participant, 'conversation_viewed', context: ['conversation_id' => $found->id]);
        }

        $messages = ConversationMessageResource::collection(
            $this->conversations->messages($found, $request->integer('per_page', 50)),
        );

        return apiResponse('Conversation fetched successfully.', 200, [
            'conversation' => ConversationResource::make($found)->resolve(),
            'messages' => $messages->response()->getData(true),
        ]);
    }

    /**
     * A hotel user reads their own hotel; a super admin must name one.
     */
    private function hotel(ConversationIndexRequest $request): Hotel
    {
        return resolveHotel($request->user(), $request->input('hotel_id'))
            ?? abort(422, 'Choose a hotel (hotel_id) to read its conversations.');
    }
}
