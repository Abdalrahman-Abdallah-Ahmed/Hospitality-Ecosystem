<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Team;
use App\Models\User;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Team::class);

        $query = Team::with(['hotel']);

        if (! $request->user()->isSuperAdmin()) {
            $query->where('hotel_id', $request->user()->hotel?->id);
        }

        $teams = GenericQuery::apply($query, $request);

        return apiResponse('Teams fetched successfully.', 200, $teams);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $team)
    {
        $this->authorize('view', $team);

        $team->load(['hotel', 'members']);
        return apiResponse('Team fetched successfully.', 200, $team);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Team::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        if (Team::where('hotel_id', $hotel->id)->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A team with this name already exists.',
            ]);
        }

        $team = Team::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Team created successfully.', 201, $team->load(['hotel']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        if (
            array_key_exists('name', $validated)
            && Team::where('hotel_id', $team->hotel_id)
                ->where('name', $validated['name'])
                ->where('id', '!=', $team->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'name' => 'A team with this name already exists.',
            ]);
        }

        $team->update($validated);

        return apiResponse('Team updated successfully.', 200, $team->load(['hotel']));
    }

    /**
     * Add a member to the team.
     */
    public function addMember(Request $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        if (invalidRelation($team->hotel, ['users' => $validated['user_id']])) {
            return apiResponse('The selected user does not belong to you.', 403);
        }

        $user = User::find($validated['user_id']);

        if (! $user->isEmployee()) {
            return apiResponse('Only employees can be added to a team.', 422);
        }

        $user->update(['team_id' => $team->id]);

        return apiResponse('Member added to the team successfully.', 200, $team->load(['hotel', 'members']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Team $team)
    {
        $this->authorize('delete', $team);
        $team->delete();
        return apiResponse('Team deleted successfully.', 200);
    }
}
