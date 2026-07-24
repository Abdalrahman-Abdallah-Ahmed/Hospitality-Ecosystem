<?php

namespace App\Http\Controllers;

use App\Enums\SenderType;
use App\Http\Requests\IdentifySenderRequest;
use App\Services\SenderRecognitionService;
use App\Support\RecognizedSender;

class SenderRecognitionController extends Controller
{
    public function __construct(
        private readonly SenderRecognitionService $senderRecognitionService
    ) {}

    /**
     * Identify whether a WhatsApp phone number belongs to an admin, a guest,
     * or an unrecognized sender, without persisting anything.
     */
    public function identify(IdentifySenderRequest $request)
    {
        $validated = $request->validated();
        $recognition = $this->senderRecognitionService->resolve($validated['phone_number']);

        return apiResponse('Sender recognized successfully.', 200, [
            'phone_number' => $validated['phone_number'],
            'sender_type' => $recognition->type->value,
            'hotel_id' => $recognition->hotelId,
            'sender' => $this->formatSender($recognition),
            'reservation' => $recognition->reservation ? [
                'id' => $recognition->reservation->id,
                'reservation_id' => $recognition->reservation->reservation_id,
                'status' => $recognition->reservation->status->value,
                'arrival_date' => $recognition->reservation->arrival_date->toDateString(),
                'departure_date' => $recognition->reservation->departure_date->toDateString(),
            ] : null,
        ]);
    }

    private function formatSender(RecognizedSender $recognition): ?array
    {
        return match ($recognition->type) {
            SenderType::ADMIN => [
                'id' => $recognition->sender->id,
                'name' => $recognition->sender->name,
                'email' => $recognition->sender->email,
                'role' => $recognition->sender->role->value,
            ],
            SenderType::GUEST => [
                'id' => $recognition->sender->id,
                'first_name' => $recognition->sender->first_name,
                'last_name' => $recognition->sender->last_name,
            ],
            SenderType::UNKNOWN => null,
        };
    }
}
