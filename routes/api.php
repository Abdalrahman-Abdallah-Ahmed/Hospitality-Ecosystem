<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisterUserController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WhatsAppDeviceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->group(function () {
    Route::post('/register', [RegisterUserController::class, 'apiStore']);
    Route::post('/login', [AuthenticatedSessionController::class, 'apiLogin'])->name('login');
    Route::post('/pair', [WhatsAppDeviceController::class, 'pair']);


    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
        Route::get('/user', function (Request $request) {
            return apiResponse('Authenticated user fetched successfully.', 200, $request->user());
        });

        Route::resource('/service', ServiceController::class)->except(['edit', 'create']);

        Route::resource('reservation', ReservationController::class)->except(['edit', 'create']);
        Route::resource('hotel',HotelController::class)->except(['edit', 'create']);

        Route::post('/connect', [WhatsAppDeviceController::class, 'connect']);
    });
});
