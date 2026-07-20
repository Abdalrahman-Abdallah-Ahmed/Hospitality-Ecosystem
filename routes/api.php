<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisterUserController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ServiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->group(function () {
    Route::post('/register', [RegisterUserController::class, 'apiStore']);
    Route::post('/login', [AuthenticatedSessionController::class, 'apiLogin'])->name('login');


    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::resource('/service', ServiceController::class)->except(['edit', 'create']);

        //TODO: Not working correctly, need to fix the import functionality
        Route::post('/reservation/import', [ReservationController::class, 'importFromExcel']);
        Route::resource('reservation', ReservationController::class)->except(['edit', 'create']);
    });
});