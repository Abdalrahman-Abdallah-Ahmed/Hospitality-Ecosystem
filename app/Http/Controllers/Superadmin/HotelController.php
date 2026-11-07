<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\HotelIndexRequest;
use App\Http\Requests\Superadmin\StoreHotelRequest;
use App\Http\Requests\Superadmin\SuspendRequest;
use App\Http\Requests\Superadmin\UpdateHotelRequest;
use App\Http\Resources\Superadmin\HotelResource;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Services\Superadmin\HotelAdministration;
use App\Services\Superadmin\Suspension;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * Hotels across every account. The route guard is the authorization: this
 * calls no tenant policy. A hotel's own settings stay with its admin, on the
 * tenant HotelController.
 */
class HotelController extends Controller
{
    public function __construct(
        private readonly HotelAdministration $hotels,
        private readonly Suspension $suspension,
    ) {}

    public function index(HotelIndexRequest $request): JsonResponse
    {
        $query = Hotel::query()->withSuspension();

        $suspended = $request->suspendedFilter();

        if ($suspended !== null) {
            $suspended ? $query->suspended() : $query->notSuspended();
        }

        return apiResponse('Hotels fetched successfully.', 200, HotelResource::collection(GenericQuery::apply($query, $request)));
    }

    public function store(StoreHotelRequest $request, HotelGroup $account): JsonResponse
    {
        $hotel = $this->hotels->create($account, $request->validated());

        return apiResponse('Hotel created successfully.', 201, HotelResource::make($hotel));
    }

    public function show(Hotel $hotel): JsonResponse
    {
        return apiResponse('Hotel fetched successfully.', 200, HotelResource::make($hotel));
    }

    public function update(UpdateHotelRequest $request, Hotel $hotel): JsonResponse
    {
        $hotel = $this->hotels->update($hotel, $request->validated());

        return apiResponse('Hotel updated successfully.', 200, HotelResource::make($hotel));
    }

    public function destroy(Hotel $hotel): JsonResponse
    {
        $this->hotels->delete($hotel);

        return apiResponse('Hotel deleted successfully.');
    }

    public function suspend(SuspendRequest $request, Hotel $hotel): JsonResponse
    {
        $hotel = $this->suspension->suspendHotel($hotel, $request->validated('reason'), $request->user());

        return apiResponse('Hotel suspended successfully.', 200, HotelResource::make($hotel));
    }

    public function reactivate(Hotel $hotel): JsonResponse
    {
        $hotel = $this->suspension->reactivateHotel($hotel);

        return apiResponse('Hotel reactivated successfully.', 200, HotelResource::make($hotel));
    }
}
