<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiAdvisorController;
use App\Http\Controllers\AiInsightsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisterUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelPolicyController;
use App\Http\Controllers\KnowledgeBaseArticleController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\TaskCategoryController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp', [WhatsAppController::class, 'whatsappVerify']);
Route::post('/whatsapp', [WhatsAppController::class, 'whatsappWebhook'])
    ->middleware('whatsapp.signature');

Route::middleware('api.key')->group(function () {
    Route::post('/register', [RegisterUserController::class, 'apiStore']);
    Route::post('/login', [AuthenticatedSessionController::class, 'apiLogin'])->name('login');
    Route::post('/pair', [WhatsAppController::class, 'pair']);
    Route::get('/check-paired', [WhatsAppController::class, 'checkPaired']);


    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
        Route::get('/user', function (Request $request) {
            return apiResponse('Authenticated user fetched successfully.', 200, $request->user());
        });
        Route::post('/connect', [WhatsAppController::class, 'connect']);
        Route::get('/dashboard', [DashboardController::class, 'generalData']);

        Route::resource('/activity', ActivityController::class)->except(['edit', 'create']);
        Route::resource('/reservation', ReservationController::class)->except(['edit', 'create']);
        Route::resource('/hotel',HotelController::class)->except(['edit', 'create']);
        Route::resource('/room', RoomController::class)->except(['edit', 'create']);
        Route::resource('/guest', GuestController::class)->except(['edit', 'create']);
        Route::resource('/hotel-policy', HotelPolicyController::class)->except(['edit', 'create', 'show']);
        Route::resource('/team', TeamController::class)->except(['edit', 'create']);
        Route::post('/team/{team}/members', [TeamController::class, 'addMember']);
        Route::resource('/task-category', TaskCategoryController::class)->except(['edit', 'create']);
        Route::resource('/task', TaskController::class)->except(['edit', 'create']);
        Route::resource('/users', UserController::class)->except(['edit', 'create']);
        Route::resource('/ai-insights', AiInsightsController::class)->except(['edit', 'create', 'show', 'update', 'destroy']);
        Route::resource('/knowledge-base-articles',KnowledgeBaseArticleController::class)->except(['edit', 'create']);
        Route::post('/ai-advisor/chat', [AiAdvisorController::class, 'chat']);
    });
});
