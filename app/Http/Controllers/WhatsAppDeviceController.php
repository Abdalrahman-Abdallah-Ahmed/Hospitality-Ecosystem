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

        $whatsappDevice = WhatsAppDevice::create([
            'user_id' => $user->id,
            'phone_number' => $request->input('phone_number'),
            'hotel_id' => $user->hotel?->id,
            'wa_user_id' => $request->input('wa_user_id'),
            'status' => 'pending',
        ]);

        $token = $user->createToken('whatsapp_device_token')->plainTextToken;
        return apiResponse('WhatsApp device token created successfully.', 200, [
            'device' => $whatsappDevice->id,
            'token' => $token,
        ]);
    }
    public function pair(WhatsAppDevicePairRequest $request)
    {
        $validated = $request->validated();

        $accessToken = PersonalAccessToken::findToken($validated['token'] ?? '');

        if (! $accessToken) {
            return apiResponse('Invalid token.', 401);
        }

        $user = $accessToken->tokenable;
        if (! $user || ! $user->hotel?->id) {
            return apiResponse('User is not associated with any hotel.', 400);
        }

        if ($user->whatsappDevice) {
            return apiResponse('User already has a paired WhatsApp device.', 400);
        }

        $device = WhatsAppDevice::create([
            'user_id' => $user->id,
            'phone_number' => $validated['phone_number'],
            'hotel_id' => $user->hotel->id,
            'wa_user_id' => $validated['wa_user_id'],
            'status' => 'active',
        ]);

        return apiResponse('WhatsApp device paired successfully.', 201, $device);
    }
}
