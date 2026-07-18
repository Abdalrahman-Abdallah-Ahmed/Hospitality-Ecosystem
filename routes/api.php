<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ServiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [RegisteredUserController::class, 'apiStore']);
Route::post('/login', [AuthenticatedSessionController::class, 'apiLogin']);

Route::middleware('auth:sanctum')->group(function () {    
    Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::resource('/service', ServiceController::class)->except(['edit', 'create']);
});
