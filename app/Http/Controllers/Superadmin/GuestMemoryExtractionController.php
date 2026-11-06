<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GuestMemoryExtractionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestMemoryExtractionResource;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Models\GuestMemoryExtraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guest memory extractions across every hotel, for operators (SPEC-084
 * FR-036): find the ones that ended for good and re-run a failed or
 * ceiling-stopped one by hand. Super admin only, by routes/admin.php.
 */
class GuestMemoryExtractionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(GuestMemoryExtractionStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Across hotels on purpose: this is the platform operator's view.
        $extractions = GuestMemoryExtraction::withoutGlobalScope('hotel')
            ->when($validated['hotel_id'] ?? null, fn ($query, string $hotelId) => $query->where('hotel_id', $hotelId))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate($validated['per_page'] ?? 25);

        return apiResponse('Guest memory extractions fetched successfully.', 200, GuestMemoryExtractionResource::collection($extractions));
    }

    /**
     * One more attempt, and only one: attempts is set so the run that
     * follows is the last, and a failure ends it for good.
     */
    public function rerun(string $extraction): JsonResponse
    {
        $extraction = GuestMemoryExtraction::withoutGlobalScope('hotel')->findOrFail($extraction);

        if (! $extraction->status->isRerunnable()) {
            return apiResponse("An extraction that is {$extraction->status->value} cannot be re-run.", 422);
        }

        $extraction->forceFill([
            'status' => GuestMemoryExtractionStatus::PENDING_RETRY,
            'retry_at' => now(),
            'attempts' => 1,
        ])->save();

        ExtractGuestMemoriesJob::dispatch($extraction->hotel_id, $extraction->guest_id, $extraction->reservation_id);

        return apiResponse('Extraction re-run queued.', 202, GuestMemoryExtractionResource::make($extraction));
    }
}
