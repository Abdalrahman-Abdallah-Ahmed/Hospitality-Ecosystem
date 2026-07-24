<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhatsAppDevicePairRequest;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class WhatsAppDeviceController extends Controller
{

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

    public function checkPaired(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:12',
        ]);

        $whatsappDevice = WhatsAppDevice::where('phone_number', $validated['phone_number'])->first();
        $user = User::where('phone_number', $validated['phone_number'])->first();

        if ($whatsappDevice) {
            if($whatsappDevice->status !== 'active') {
                return apiResponse('User has a paired WhatsApp device, but it is not active.', 202, [
                    'paired' => true,
                    'device' => $whatsappDevice,
                ]);
            }
            return apiResponse('User has a paired WhatsApp device.', 200, [
                'paired' => true,
                'device' => $whatsappDevice,
            ]);
        }

        return apiResponse('User not paired.', 201, [
            'paired' => false,
            'user_role'=> $user?->role,
            'user_name'=> $user?->name,
        ]);
    }
}
