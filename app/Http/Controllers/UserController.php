<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Hotel;
use App\Models\User;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::with('hotel');

        if (! $request->user()->isSuperAdmin()) {
            $hotelId = $request->user()->hotel?->id;

            $query->where(function ($inner) use ($hotelId, $request) {
                $inner->where('id', $request->user()->id);

                if ($hotelId) {
                    $inner->orWhere('hotel_id', $hotelId);
                }
            });
        }

        $users = GenericQuery::apply($query, $request);

        return apiResponse('Users fetched successfully.', 200, UserResource::collection($users));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        if ($request->user()->isSuperAdmin()) {
            $hotel = ($id = $request->validated()['hotel_id'] ?? null) ? Hotel::find($id) : null;
        } else {
            $hotel = $request->user()->hotel;

            if (! $hotel) {
                return apiResponse('User does not have an associated hotel.', 403);
            }
        }

        $teamId = $validated['team_id'] ?? null;

        if ($teamId && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $user = User::create([...$validated, 'hotel_id' => $hotel?->id]);

        return apiResponse('User created successfully.', 201, UserResource::collection([$user->load('hotel')]));
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return apiResponse('User fetched successfully.', 200, UserResource::collection([$user->load('hotel')]));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $user->hotel_id ? Hotel::find($user->hotel_id) : null;
        $teamId = $validated['team_id'] ?? $user->team_id;

        if ($teamId && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $user->update([...$validated, 'hotel_id' => $user->hotel_id]);

        return apiResponse('User updated successfully.', 200, UserResource::collection([$user->load('hotel')]));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return apiResponse('User deleted successfully.', 200);
    }
}
