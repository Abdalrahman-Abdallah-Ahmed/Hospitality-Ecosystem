<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestMemoryIndexRequest;
use App\Http\Resources\GuestMemoryResource;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Support\Audit\EventLogger;
use Illuminate\Http\JsonResponse;

/**
 * What the AI remembers about a guest (SPEC-084): read and deleted by staff
 * the hotel allowed to, never written by hand.
 *
 * The guest is resolved through the tenant scope rather than route binding,
 * so another hotel's guest is not found at all (FR-028).
 */
class GuestMemoryController extends Controller
{
    public function index(GuestMemoryIndexRequest $request, string $guest): JsonResponse
    {
        $guest = Guest::query()->findOrFail($guest);
        $this->authorize('viewAny', [GuestMemory::class, $guest]);

        // Reading what the AI keeps about a guest is reading protected guest
        // data, so it is recorded (FR-029).
        EventLogger::record($guest, 'memories_viewed');

        $memories = GuestMemory::query()
            ->where('guest_id', $guest->id)
            ->when($request->validated('category'), fn ($query, string $category) => $query->where('category', $category))
            ->with('sourceStay')
            ->orderByDesc('extracted_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return apiResponse('Guest memories fetched successfully.', 200, [
            'guest_memories_enabled' => (bool) $guest->hotel?->guest_memories_enabled,
            'memories' => GuestMemoryResource::collection($memories)->response()->getData(true),
        ]);
    }

    public function destroy(string $guest, string $memory): JsonResponse
    {
        $guest = Guest::query()->findOrFail($guest);
        $memory = GuestMemory::query()->where('guest_id', $guest->id)->findOrFail($memory);

        $this->authorize('delete', $memory);

        $memory->delete();

        return apiResponse('Guest memory deleted successfully.', 200);
    }
}
