<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhatsAppDevicePairRequest;
use App\Models\WhatsAppDevice;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class WhatsAppDeviceController extends Controller
{

    public function connect(Request $request)
    {
        $user = $request->user();
        $token = $user->createToken('whatsapp_device_token')->plainTextToken;
        // Logic to connect the WhatsApp device
        // This could involve sending a request to the WhatsApp API or performing some other action
        return response()->json(['token' => $token]);
    }
    public function pair(WhatsAppDevicePairRequest $request)
    {
        $validated = $request->validated();

        $accessToken = PersonalAccessToken::findToken($validated['token'] ?? '');

        if (! $accessToken) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $user = $accessToken->tokenable;
        if (! $user || ! $user->hotel->id) {
            return response()->json(['message' => 'User is not associated with any hotel.'], 400);
        }

        if ($user->whatsappDevice) {
            return response()->json(['message' => 'User already has a paired WhatsApp device.'], 400);
        }

        $device = WhatsAppDevice::create([
            'user_id' => $user->id,
            'phone_number' => $validated['phone_number'],
            'hotel_id' => $user->hotel->id,
            'wa_user_id' => $validated['wa_user_id'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'WhatsApp device paired successfully.',
            'data' => $device,
        ]);
    }
}
