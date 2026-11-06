<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\AccountIndexRequest;
use App\Http\Requests\Superadmin\StoreAccountRequest;
use App\Http\Requests\Superadmin\UpdateAccountRequest;
use App\Http\Resources\Superadmin\AccountResource;
use App\Models\HotelGroup;
use App\Services\Superadmin\AccountAdministration;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * Accounts across the platform. The route guard is the authorization: this
 * calls no tenant policy.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountAdministration $accounts) {}

    public function index(AccountIndexRequest $request): JsonResponse
    {
        $accounts = GenericQuery::apply(
            HotelGroup::query()->withCount('hotels')->withUserCount(),
            $request,
        );

        return apiResponse('Accounts fetched successfully.', 200, AccountResource::collection($accounts));
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->create($request->validated());

        return apiResponse('Account created successfully.', 201, $this->resource($account));
    }

    public function show(HotelGroup $account): JsonResponse
    {
        return apiResponse('Account fetched successfully.', 200, $this->resource($account));
    }

    public function update(UpdateAccountRequest $request, HotelGroup $account): JsonResponse
    {
        $account = $this->accounts->update($account, $request->validated());

        return apiResponse('Account updated successfully.', 200, $this->resource($account));
    }

    public function destroy(HotelGroup $account): JsonResponse
    {
        $this->accounts->delete($account);

        return apiResponse('Account deleted successfully.');
    }

    /**
     * Read back so every response has its column defaults and counts.
     */
    private function resource(HotelGroup $account): AccountResource
    {
        return AccountResource::make(
            HotelGroup::query()->withCount('hotels')->withUserCount()->findOrFail($account->getKey()),
        );
    }
}
