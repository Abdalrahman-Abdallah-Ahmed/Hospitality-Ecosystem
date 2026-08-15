<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhatsAppDevicePairRequest;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\SenderRecognitionService;
use App\Services\WhatsAppMessageService;
use App\Support\RecognizedSender;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class WhatsAppController extends Controller
{
    public function __construct(
        private readonly SenderRecognitionService $senderRecognitionService,
        private readonly WhatsAppMessageService $whatsAppMessageService,
    ) {}

    public function connect(Request $request)
    {
        $user = $request->user();

        $token = $user->createToken('whatsapp_device_token')->plainTextToken;
        return apiResponse('WhatsApp device token created successfully.', 200, [
            'token' => $token,
        ]);
    }

    public function pair(WhatsAppDevicePairRequest $request)
    {
        $validated = $request->validated();

        $result = $this->pairDevice($validated['token'] ?? '', $validated['phone_number'], $validated['wa_user_id']);

        return match ($result['status']) {
            'invalid_token' => apiResponse('Invalid token.', 201),
            'no_hotel' => apiResponse('User is not associated with any hotel.', 202),
            'already_paired' => apiResponse('User already has a paired WhatsApp device.', 203),
            'paired' => apiResponse('WhatsApp device paired successfully.', 200, $result['device']),
        };
    }

    /**
     * Redeem a `connect()`-issued token and pair it to the given WhatsApp
     * identity. Shared by the pair() endpoint (token supplied via a form)
     * and whatsappWebhook() (token supplied as a WhatsApp message).
     *
     * @return array{status: string, device?: WhatsAppDevice}
     */
    private function pairDevice(string $token, string $phoneNumber, string $waUserId): array
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return ['status' => 'invalid_token'];
        }

        $user = $accessToken->tokenable;
        if (! $user || ! $user->hotel?->id) {
            return ['status' => 'no_hotel'];
        }

        if ($user->whatsappDevice) {
            return ['status' => 'already_paired'];
        }

        $device = WhatsAppDevice::create([
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'hotel_id' => $user->hotel->id,
            'wa_user_id' => $waUserId,
            'status' => 'active',
        ]);

        return ['status' => 'paired', 'device' => $device];
    }

    /**
     * The WhatsApp reply text for each pairDevice() outcome, used when
     * pairing is attempted by sending a token as a WhatsApp message.
     */
    private function pairingReplyFor(string $status): string
    {
        return match ($status) {
            'invalid_token' => "That code isn't valid or has expired. Please generate a new one from the dashboard.",
            'no_hotel' => 'Your account is not associated with a hotel yet, so it cannot be paired.',
            'already_paired' => 'This account already has a paired WhatsApp device.',
            'paired' => "You're all set! This number is now paired to your account.",
        };
    }

    /**
     * Check whether a phone number contacting us already has a paired
     * WhatsApp device, and whether it's active. Used both by the public
     * checkPaired() endpoint (the frontend polls this for pairing status)
     * and internally by whatsappWebhook().
     *
     * @return array{paired: bool, active: bool, device: ?WhatsAppDevice, user_role: mixed, user_name: ?string}
     */
    private function pairingStatus(string $phoneNumber): array
    {
        $whatsappDevice = WhatsAppDevice::where('phone_number', $phoneNumber)->first();
        $user = User::where('phone_number', $phoneNumber)->first();

        if ($whatsappDevice) {
            return [
                'paired' => true,
                'active' => $whatsappDevice->status === 'active',
                'device' => $whatsappDevice,
                'user_role' => null,
                'user_name' => null,
            ];
        }

        return [
            'paired' => false,
            'active' => false,
            'device' => null,
            'user_role' => $user?->role,
            'user_name' => $user?->name,
        ];
    }

    /**
     * Public endpoint the frontend polls to check WhatsApp pairing status
     * for a phone number.
     */
    public function checkPaired(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:12',
        ]);

        $pairing = $this->pairingStatus($validated['phone_number']);

        if ($pairing['paired']) {
            return $pairing['active']
                ? apiResponse('User has a paired WhatsApp device.', 200, [
                    'paired' => true,
                    'device' => $pairing['device'],
                ])
                : apiResponse('User has a paired WhatsApp device, but it is not active.', 202, [
                    'paired' => true,
                    'device' => $pairing['device'],
                ]);
        }

        return apiResponse('User not paired.', 201, [
            'paired' => false,
            'user_role' => $pairing['user_role'],
            'user_name' => $pairing['user_name'],
        ]);
    }

    /**
     * Identify whether a WhatsApp phone number belongs to an admin, a guest,
     * or an unrecognized sender, without persisting anything. No longer a
     * public endpoint — used internally by whatsappWebhook().
     */
    private function identify(string $phoneNumber): RecognizedSender
    {
        return $this->senderRecognitionService->resolve($phoneNumber);
    }

    /**
     * Meta's subscription handshake: echo the challenge back once the
     * verify token matches, so Meta knows this URL is a legitimate webhook.
     */
    public function whatsappVerify(Request $request)
    {
        if ($request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('services.whatsapp.verify_token'), (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        abort(403);
    }

    /**
     * Inbound WhatsApp message webhook. Kept thin and fast on purpose (Meta
     * expects a quick ack): sender recognition and the admin pairing check
     * happen here synchronously (cheap DB reads via identify()/checkPaired()),
     * then the slow part — invoking the agent and sending the reply — is
     * handed off to a queued job.
     */
    public function whatsappWebhook(Request $request)
    {
        $value = data_get($request->input('entry'), '0.changes.0.value');
        $message = data_get($value, 'messages.0');

        if (! $message) {
            // Not an inbound text message (e.g. a delivery/read status update) — nothing to do.
            return response('', 200);
        }

        $phoneNumber = $message['from'];
        $text = trim($message['text']['body'] ?? '');

        // An admin pairs by sending the token connect() gave them, as a plain
        // WhatsApp message — same logic as the pair() endpoint, just fed a
        // token from chat instead of a form. Sanctum's plaintext token format
        // is always "{id}|{plaintext}", so that's a safe, cheap heuristic —
        // and even a false positive just gets a harmless "invalid code" reply
        // via pairDevice(), rather than silently misparsing a real message.
        if (str_contains($text, '|')) {
            $waUserId = data_get($value, 'contacts.0.wa_id', $phoneNumber);
            $result = $this->pairDevice($text, $phoneNumber, $waUserId);

            $this->whatsAppMessageService->send($phoneNumber, $this->pairingReplyFor($result['status']));

            return response('', 200);
        }

        $recognition = $this->identify($phoneNumber);
        $pairing = $this->pairingStatus($phoneNumber);

        ProcessInboundWhatsAppMessageJob::dispatch(
            phoneNumber: $phoneNumber,
            messageText: $text,
            senderType: $recognition->type,
            sender: $recognition->sender,
            hotel: $recognition->hotelId ? Hotel::find($recognition->hotelId) : null,
            reservation: $recognition->reservation,
            devicePaired: $pairing['paired'] && $pairing['active'],
        );

        return response('', 200);
    }
}
