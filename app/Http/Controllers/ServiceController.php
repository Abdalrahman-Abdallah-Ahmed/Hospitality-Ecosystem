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
        $validated = $request->validated();

        if ($validated['hotel_id'] !== $request->user()->hotel?->id) {
            return apiResponse('The selected hotel does not belong to you.', 403);
        }

        $service = Service::create($validated);

        return apiResponse('Service created successfully.', 201, $service);
    }

    /**
     * Display the specified resource.
     */
    public function show(Service $service)
    {
        if ($service->hotel_id !== request()->user()->hotel?->id) {
            return apiResponse('You do not have access to this service.', 403);
        }

        return apiResponse('Service fetched successfully.', 200, $service);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Service $service)
    {
        if ($service->hotel_id !== $request->user()->hotel?->id) {
            return apiResponse('You do not have access to this service.', 403);
        }

        $service->update($request->validated());

        return apiResponse('Service updated successfully.', 200, $service);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service)
    {
        if ($service->hotel_id !== request()->user()->hotel?->id) {
            return apiResponse('You do not have access to this service.', 403);
        }

        $service->delete();

        return apiResponse('Service deleted successfully.', 200);
    }
}
