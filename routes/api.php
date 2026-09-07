<?php

use App\Http\Controllers\ActivityCategoryController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\UsageController;
use App\Http\Controllers\AiAdvisorController;
use App\Http\Controllers\AiInsightsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisterUserController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelPolicyController;
use App\Http\Controllers\KnowledgeBaseArticleController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\TaskCategoryController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TransactionController;
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

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
        Route::get('/user', function (Request $request) {
            return apiResponse('Authenticated user fetched successfully.', 200, $request->user());
        });
        Route::post('/connect', [WhatsAppController::class, 'connect']);
        Route::get('/dashboard', [DashboardController::class, 'generalData']);

        Route::resource('/activity', ActivityController::class)->except(['edit', 'create']);
        Route::resource('/activity-category', ActivityCategoryController::class)->except(['edit', 'create']);
        Route::post('/reservation/import', [ReservationController::class, 'import']);
        Route::resource('/reservation', ReservationController::class)->except(['edit', 'create']);
        Route::post('/reservation/{reservation}/recommendations', [RecommendationController::class, 'generate']);
        Route::post('/recommendation/{recommendation}/outcome', [RecommendationController::class, 'recordOutcome']);
        Route::resource('/recommendation', RecommendationController::class)->except(['edit', 'create', 'store']);
        Route::post('/booking/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::post('/booking', [BookingController::class, 'store']);
        Route::get('/booking', [BookingController::class, 'index']);
        Route::get('/booking/{booking}', [BookingController::class, 'show']);
        Route::get('/analytics/conversion', [AnalyticsController::class, 'conversion']);
        Route::post('/transaction/import', [TransactionController::class, 'import']);
        Route::post('/transaction/{transaction}/reverse', [TransactionController::class, 'reverse']);
        Route::get('/transaction', [TransactionController::class, 'index']);
        Route::get('/transaction/{transaction}', [TransactionController::class, 'show']);
        Route::resource('/hotel', HotelController::class)->except(['edit', 'create']);
        Route::resource('/room', RoomController::class)->except(['edit', 'create']);
        Route::resource('/guest', GuestController::class)->except(['edit', 'create']);
        Route::resource('/hotel-policy', HotelPolicyController::class)->except(['edit', 'create', 'show']);
        Route::resource('/team', TeamController::class)->except(['edit', 'create']);
        Route::post('/team/{team}/members', [TeamController::class, 'addMember']);
        Route::resource('/task-category', TaskCategoryController::class)->except(['edit', 'create']);
        Route::resource('/task', TaskController::class)->except(['edit', 'create']);
        Route::resource('/users', UserController::class)->except(['edit', 'create']);
        Route::resource('/ai-insights', AiInsightsController::class)->except(['edit', 'create', 'show', 'update', 'destroy']);
        Route::resource('/knowledge-base-articles', KnowledgeBaseArticleController::class)->except(['edit', 'create']);
        Route::post('/ai-advisor/chat', [AiAdvisorController::class, 'chat']);
        Route::get('/history/{type}/{id}', [HistoryController::class, 'show']);

        // Reporting across every account, so it sits behind the super-admin
        // role rather than a per-model policy — there is no single model to
        // authorise against.
        Route::middleware('super_admin')->prefix('admin')->group(function () {
            Route::get('/usage', [UsageController::class, 'index']);
        });
    });
});
