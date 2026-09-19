<?php

namespace App\Http\Controllers;

use App\Enums\InboundMessageStatus;
use App\Http\Requests\CheckPairedRequest;
use App\Http\Requests\WhatsAppDevicePairRequest;
use App\Http\Resources\WhatsAppDeviceResource;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppInboundMessage;
use App\Services\SenderRecognitionService;
use App\Support\PhoneNumber;
use App\Support\RecognizedSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class WhatsAppController extends Controller
{
    /**
     * How long a pairing code stays redeemable. Long enough to open WhatsApp
     * and send it; short enough that a code someone glimpsed is soon useless.
     */
    private const PAIRING_CODE_TTL_MINUTES = 15;

    public function __construct(
        private readonly SenderRecognitionService $senderRecognitionService,
    ) {}

    public function connect(Request $request)
    {
        $token = $request->user()->createToken(
            WhatsAppDevice::PAIRING_TOKEN_NAME,
            expiresAt: now()->addMinutes(self::PAIRING_CODE_TTL_MINUTES),
        )->plainTextToken;

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
            'paired' => apiResponse('WhatsApp device paired successfully.', 200, WhatsAppDeviceResource::make($result['device'])),
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

        if (! $this->isRedeemablePairingCode($accessToken)) {
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

        // Single use: a redeemed code must not be able to pair anything again.
        $accessToken->delete();

        return ['status' => 'paired', 'device' => $device];
    }

    /**
     * Only an unexpired code issued by connect(). A login token is refused
     * even though Sanctum would accept it: pairing hands a WhatsApp number
     * the owner's advisor access, and a code the owner deliberately issued
     * should be the only way to grant that.
     */
    private function isRedeemablePairingCode(?PersonalAccessToken $token): bool
    {
        return $token !== null
            && $token->name === WhatsAppDevice::PAIRING_TOKEN_NAME
            && $token->expires_at?->isFuture() === true;
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
     * WhatsApp device, and whether it's active. Used both by the
     * authenticated checkPaired() endpoint (the dashboard polls this for
     * pairing status) and internally by whatsappWebhook().
     *
     * @return array{paired: bool, active: bool, device: ?WhatsAppDevice, user_role: mixed, user_name: ?string}
     */
    private function pairingStatus(string $phoneNumber): array
    {
        $whatsappDevice = WhatsAppDevice::where('phone_number', $phoneNumber)->first();

        // Inside an authenticated request the lookup is limited to the
        // caller's own hotels, so a phone number cannot be used to find out
        // who works at another property. The webhook has no tenant context
        // and matches across every hotel, which sender recognition needs.
        // The device lookup above is limited the same way by BelongsToHotel.
        //
        // $phoneNumber arrives as digits (see PhoneNumber); users.phone_number
        // holds whatever was typed, so it is compared by its digits too.
        $hotelIds = TenantContext::hotelIds();
        $user = User::whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$phoneNumber])
            ->when($hotelIds !== null, fn ($query) => $query->whereIn('hotel_id', $hotelIds))
            ->first();

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
     * Endpoint the dashboard polls to check WhatsApp pairing status for a
     * phone number within the caller's own hotels.
     */
    public function checkPaired(CheckPairedRequest $request)
    {
        $pairing = $this->pairingStatus($request->validated('phone_number'));

        if ($pairing['paired']) {
            return $pairing['active']
                ? apiResponse('User has a paired WhatsApp device.', 200, [
                    'paired' => true,
                    'device' => WhatsAppDeviceResource::make($pairing['device']),
                ])
                : apiResponse('User has a paired WhatsApp device, but it is not active.', 202, [
                    'paired' => true,
                    'device' => WhatsAppDeviceResource::make($pairing['device']),
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
     * expects a quick ack, and redelivers when it doesn't get one): sender
     * recognition and the admin pairing check happen here synchronously
     * (cheap, indexed DB reads), then everything slow — the agent and every
     * outbound send — is handed off to queued jobs.
     *
     * One delivery can batch several messages across entries and changes;
     * each is handled on its own. Payloads with no messages (delivery/read
     * status updates) fall through to the ack.
     */
    public function whatsappWebhook(Request $request)
    {
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);

                foreach ((array) data_get($value, 'messages', []) as $message) {
                    if (is_array($message) && isset($message['from'])) {
                        $this->handleInboundMessage($message, $value);
                    }
                }
            }
        }

        return response('', 200);
    }

    /**
     * Record the message once (a redelivery of the same wamid stops here),
     * apply the per-sender rate limit, then either redeem a pairing code or
     * queue the conversation turn.
     */
    private function handleInboundMessage(array $message, array $value): void
    {
        $phoneNumber = (string) $message['from'];
        $type = $message['type'] ?? 'text';

        $inbound = $this->recordInbound($message, $phoneNumber, $type);

        if (! $inbound) {
            return;
        }

        if ($this->throttled($phoneNumber)) {
            $inbound->update(['status' => InboundMessageStatus::THROTTLED]);

            return;
        }

        // An image message has no `text.body` — whatever the sender typed
        // alongside the photo (if anything) comes through as `image.caption`
        // instead. The image itself is referenced by ID only; the bytes are
        // fetched later, from the job, via WhatsAppMessageService::downloadMedia().
        $text = trim($type === 'image' ? ($message['image']['caption'] ?? '') : ($message['text']['body'] ?? ''));
        $imageMediaId = $type === 'image' ? ($message['image']['id'] ?? null) : null;

        // An admin pairs by sending the token connect() gave them, as a plain
        // WhatsApp message — same logic as the pair() endpoint, just fed a
        // token from chat instead of a form. Sanctum's plaintext token format
        // is always "{id}|{plaintext}", so that's a safe, cheap heuristic —
        // and even a false positive just gets a harmless "invalid code" reply
        // via pairDevice(), rather than silently misparsing a real message.
        if ($type === 'text' && str_contains($text, '|')) {
            $waUserId = data_get($value, 'contacts.0.wa_id', $phoneNumber);
            $result = $this->pairDevice($text, $phoneNumber, $waUserId);

            $inbound->update(['status' => InboundMessageStatus::PAIRING]);
            SendWhatsAppMessageJob::dispatch($phoneNumber, $this->pairingReplyFor($result['status']));

            return;
        }

        $recognition = $this->identify($phoneNumber);
        $pairing = $this->pairingStatus($phoneNumber);
        $hotel = $recognition->hotelId ? Hotel::find($recognition->hotelId) : null;

        $inbound->update(['hotel_id' => $hotel?->id]);

        ProcessInboundWhatsAppMessageJob::dispatch(
            inbound: $inbound,
            phoneNumber: $phoneNumber,
            messageText: $text,
            senderType: $recognition->type,
            sender: $recognition->sender,
            hotel: $hotel,
            reservation: $recognition->reservation,
            devicePaired: $pairing['paired'] && $pairing['active'],
            imageMediaId: $imageMediaId,
        );
    }

    /**
     * Store the message, or return null when this wamid was already stored —
     * a redelivery Meta sent because an earlier ack was slow or lost. The
     * unique index decides, so two concurrent deliveries cannot both win.
     */
    private function recordInbound(array $message, string $phoneNumber, string $type): ?WhatsAppInboundMessage
    {
        $attributes = [
            'phone_number' => $phoneNumber,
            'message_type' => $type,
            'status' => InboundMessageStatus::RECEIVED,
        ];

        $wamid = $message['id'] ?? null;

        if (! $wamid) {
            return WhatsAppInboundMessage::create($attributes);
        }

        $inbound = WhatsAppInboundMessage::createOrFirst(['wamid' => $wamid], $attributes);

        return $inbound->wasRecentlyCreated ? $inbound : null;
    }

    /**
     * Per-sender burst limit. The first message over the limit gets one
     * notice so the sender knows why they are not being answered; the rest
     * of the window is dropped silently.
     */
    private function throttled(string $phoneNumber): bool
    {
        $limit = max(1, (int) config('services.whatsapp.inbound_per_minute'));
        $attempts = RateLimiter::hit('whatsapp-inbound:'.$phoneNumber, 60);

        if ($attempts <= $limit) {
            return false;
        }

        if ($attempts === $limit + 1) {
            SendWhatsAppMessageJob::dispatch(
                $phoneNumber,
                "You're sending messages faster than we can answer. Please wait a minute and try again."
            );
        }

        return true;
    }
}
