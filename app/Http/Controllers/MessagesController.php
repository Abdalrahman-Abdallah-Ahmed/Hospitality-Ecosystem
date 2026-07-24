<?php

namespace App\Http\Controllers;

use App\Http\Requests\MessagesStoreRequest;
use App\Models\Guest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessagesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MessagesStoreRequest $request)
    {
        $validated = $request->validated();

        $sender = User::where('phone_number', $validated['phone_number'])->first();
        if(!$sender){
            
        }

        $message = Message::create([
            'conversation_id' => $validated['phone_number'] ?? null,
            'sender_id' => $sender->id,
            'reservation_id' => $validated['reservation_id'] ?? null,
            'content' => $validated['content'],
            'message_type' => $validated['message_type'],
            'is_ai_generated' => $validated['is_ai_generated'] ?? false,
            'delivery_status' => $validated['delivery_status'] ?? 'pending',
            'sent_at' => now(),
        ]);
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
