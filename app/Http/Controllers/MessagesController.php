<?php

namespace App\Http\Controllers;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Http\Requests\MessagesStoreRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\SenderRecognitionService;
use App\Support\RecognizedSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagesController extends Controller
{
    public function __construct(
        private readonly SenderRecognitionService $senderRecognitionService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * The sender is always recognized (admin, guest, or unknown) and the
     * message is always persisted and attached to a conversation, regardless
     * of what the sender turned out to be.
     */
    public function store(MessagesStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $recognition = $this->senderRecognitionService->resolve($validated['phone_number']);

        $conversation = $this->findOrCreateConversation($validated, $recognition);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $recognition->sender?->id,
            'sender_type' => $recognition->sender?->getMorphClass(),
            'reservation_id' => $validated['reservation_id'] ?? $recognition->reservation?->id,
            'content' => $validated['content'],
            'message_type' => $validated['message_type'],
            'is_ai_generated' => $validated['is_ai_generated'] ?? false,
            'delivery_status' => $validated['delivery_status'] ?? 'pending',
            'sent_at' => now(),
        ]);

        return apiResponse('Message created successfully.', 201, [
            'sender_type' => $recognition->type->value,
            'message' => $message->load('conversation', 'sender'),
        ]);
    }

    private function findOrCreateConversation(array $validated, RecognizedSender $recognition): Conversation
    {
        $conversation = Conversation::firstOrNew([
            'id' => $validated['phone_number'],
        ]);

        $conversation->fill([
            'sender_id' => $recognition->sender?->id,
            'sender_type' => $recognition->sender?->getMorphClass(),
            'hotel_id' => $recognition->hotelId,
            'reservation_id' => $validated['reservation_id'] ?? $recognition->reservation?->id,
            'channel' => ConversationChannel::WHATSAPP->value,
            'status' => ConversationStatus::OPEN->value,
        ]);

        if (! $conversation->exists) {
            $conversation->started_at = now();
        }

        $conversation->save();

        return $conversation;
    }

    /**
     * Display the specified resource.
     */
    public function show(Message $message)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Message $message)
    {
        //
    }
}
