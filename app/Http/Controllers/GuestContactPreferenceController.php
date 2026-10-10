<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactPreferenceRequest;
use App\Http\Resources\GuestResource;
use App\Models\Guest;
use App\Services\GuestContactPreferenceService;
use Illuminate\Http\JsonResponse;

/**
 * Staff record a guest's wish about proactive messages and unsolicited
 * offers — usually a guest at the desk asking to receive them again
 * (SPEC-073 FR-036).
 */
class GuestContactPreferenceController extends Controller
{
    public function update(UpdateContactPreferenceRequest $request, Guest $guest, GuestContactPreferenceService $preferences): JsonResponse
    {
        $this->authorize('update', $guest);

        $request->boolean('proactive_opted_out')
            ? $preferences->optOut($guest, GuestContactPreferenceService::SOURCE_STAFF)
            : $preferences->optIn($guest, GuestContactPreferenceService::SOURCE_STAFF);

        return apiResponse('Contact preference updated successfully.', 200, GuestResource::make($guest->refresh()));
    }
}
