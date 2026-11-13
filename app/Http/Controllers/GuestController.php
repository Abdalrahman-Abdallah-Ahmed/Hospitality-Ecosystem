<?php

namespace App\Http\Controllers;

use App\Enums\ExportFormat;
use App\Http\Controllers\Concerns\ExportsLists;
use App\Http\Requests\Generic\GenericExportRequest;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\GuestResource;
use App\Models\Guest;
use App\Services\Guests\GuestRegistrar;
use App\Support\Export\GuestsExport;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class GuestController extends Controller
{
    use ExportsLists;

    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        $guests = GenericQuery::paginate($this->listQuery($request), $request);

        return apiResponse('Guests fetched successfully.', 200, GuestResource::collection($guests));
    }

    /**
     * The filtered, sorted list without pagination. Shared by index() and
     * export(), so an export holds exactly what the list shows.
     */
    private function listQuery(GenericIndexRequest $request): Builder
    {
        return GenericQuery::query(Guest::with(['hotel', 'reservations', 'conversations', 'stays']), $request);
    }

    /**
     * Download the guest list as Excel, CSV or SQL, filtered and sorted exactly
     * as the list is (spec 021).
     */
    public function export(GenericExportRequest $request)
    {
        return $this->exportList('guests', Guest::class, $this->listQuery($request), $request,
            fn (Builder $query, ExportFormat $format) => new GuestsExport($query, $format));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request, GuestRegistrar $registrar): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $validated = $request->validated();

        $hotel = resolveHotel($request->user());
        if (! $hotel) {
            return apiResponse('You must belong to a hotel.', 403);
        }

        // Duplicate rules live in GuestRegistrar, shared with the Admin AI.
        // An existing person is returned as if created, as before.
        ['guest' => $guest] = $registrar->register($hotel, unsetAttributes($validated, ['hotel_id']));

        return apiResponse('Guest created successfully.', 201, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Display the specified resource.
     */
    public function show(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        return apiResponse('Guest fetched successfully.', 200, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Guest $guest, GuestRegistrar $registrar): JsonResponse
    {
        $this->authorize('update', $guest);

        $registrar->update($guest, unsetAttributes($request->validated(), ['hotel_id']));

        return apiResponse('Guest updated successfully.', 200, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Guest $guest): JsonResponse
    {
        $this->authorize('delete', $guest);

        $guest->delete();

        return apiResponse('Guest deleted successfully.', 200);
    }
}
