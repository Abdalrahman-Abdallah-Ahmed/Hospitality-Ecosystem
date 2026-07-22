<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\GlopalIndexRequest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GlopalIndexRequest $request)
    {
        $reservations = Reservation::with(['hotel', 'guest', 'room'])
        ->where('hotel_id', $request->user()->hotel_id)
        ->get();
        return apiResponse('Reservations fetched successfully.', 200, $reservations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        //
    }
}
