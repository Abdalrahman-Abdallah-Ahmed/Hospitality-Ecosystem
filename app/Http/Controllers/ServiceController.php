<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Service;
use App\Support\RequestRules\GenericQuery;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Service::class);

        $services = GenericQuery::apply(
            Service::where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Services fetched successfully.', 200, $services);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', Service::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'serviceCategories' => $validated['category_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $service = Service::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Service created successfully.', 201, $service);
    }

    /**
     * Display the specified resource.
     */
    public function show(Service $service)
    {
        $this->authorize('view', $service);

        return apiResponse('Service fetched successfully.', 200, $service);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Service $service)
    {
        $this->authorize('update', $service);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'serviceCategories' => $validated['category_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $service->update([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Service updated successfully.', 200, $service);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service)
    {
        $this->authorize('delete', $service);

        $service->delete();

        return apiResponse('Service deleted successfully.', 200);
    }
}
