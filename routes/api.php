<?php

use App\Http\Controllers\AccountUsageController;
use App\Http\Controllers\ActivityAvailabilityController;
use App\Http\Controllers\ActivityCategoryController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiActivityController;
use App\Http\Controllers\AiAdvisorController;
use App\Http\Controllers\AiInsightsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisterUserController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingCancellationRequestController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestContactPreferenceController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestMemoryController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelPolicyController;
use App\Http\Controllers\HousekeepingBoardController;
use App\Http\Controllers\KnowledgeBaseArticleController;
use App\Http\Controllers\KnowledgeDocumentController;
use App\Http\Controllers\MaintenanceTaskController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProactiveMessageController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationImportController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomHousekeepingController;
use App\Http\Controllers\RoomOutOfOrderController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\TaskCategoryController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskInspectionController;
use App\Http\Controllers\TaskIssueController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp', [WhatsAppController::class, 'whatsappVerify']);
Route::post('/whatsapp', [WhatsAppController::class, 'whatsappWebhook'])
    ->middleware('whatsapp.signature');

Route::middleware('api.key')->group(function () {
    Route::post('/register', [RegisterUserController::class, 'apiStore'])->middleware('throttle:register');
    Route::post('/login', [AuthenticatedSessionController::class, 'apiLogin'])->middleware('throttle:login')->name('login');
    Route::post('/pair', [WhatsAppController::class, 'pair'])->middleware('throttle:pair');

    Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'apiLogout']);
        Route::get('/user', function (Request $request) {
            return apiResponse('Authenticated user fetched successfully.', 200, UserResource::make($request->user()->load(['hotel', 'team', 'staffRole'])));
        });
        Route::post('/connect', [WhatsAppController::class, 'connect']);
        Route::get('/check-paired', [WhatsAppController::class, 'checkPaired']);
        Route::get('/dashboard', [DashboardController::class, 'generalData']);

        Route::get('/activity/{activity}/availability', ActivityAvailabilityController::class);
        Route::resource('/activity', ActivityController::class)->except(['edit', 'create']);
        Route::resource('/activity-category', ActivityCategoryController::class)->except(['edit', 'create']);
        Route::get('/availability', [AvailabilityController::class, 'index']);
        Route::get('/available-channels', [ReservationController::class, 'availableChannels']);
        Route::post('/reservation/import', [ReservationController::class, 'import']);
        // Before the /reservation resource, so "imports" is never bound as a reservation id.
        Route::get('/reservation/imports', [ReservationImportController::class, 'index']);
        Route::get('/reservation/imports/{reservationImport}', [ReservationImportController::class, 'show']);
        Route::get('/reservation/imports/{reservationImport}/rows', [ReservationImportController::class, 'rows']);
        Route::get('/reservation/imports/{reservationImport}/report', [ReservationImportController::class, 'report']);
        Route::post('/reservation/{reservation}/check-in', [ReservationController::class, 'checkIn']);
        Route::post('/reservation/{reservation}/check-out', [ReservationController::class, 'checkOut']);
        Route::post('/reservation/{reservation}/no-show', [ReservationController::class, 'noShow']);
        Route::post('/reservation/{reservation}/undo-no-show', [ReservationController::class, 'undoNoShow']);
        Route::resource('/reservation', ReservationController::class)->except(['edit', 'create']);
        Route::get('/stays/arrivals', [StayController::class, 'arrivals']);
        Route::get('/stays/departures', [StayController::class, 'departures']);
        Route::get('/stays/in-house', [StayController::class, 'inHouse']);
        Route::get('/stays', [StayController::class, 'index']);
        Route::get('/stays/{stay}', [StayController::class, 'show']);
        Route::post('/stays/{stay}/check-in', [StayController::class, 'checkIn']);
        Route::post('/stays/{stay}/check-out', [StayController::class, 'checkOut']);
        Route::post('/stays/{stay}/no-show', [StayController::class, 'noShow']);
        Route::post('/stays/{stay}/undo-no-show', [StayController::class, 'undoNoShow']);
        Route::post('/reservation/{reservation}/recommendations', [RecommendationController::class, 'generate']);
        Route::post('/recommendation/{recommendation}/outcome', [RecommendationController::class, 'recordOutcome']);
        Route::post('/recommendation/{recommendation}/approve', [RecommendationController::class, 'approve']);
        Route::post('/recommendation/{recommendation}/reject', [RecommendationController::class, 'reject']);
        Route::post('/recommendations/decide', [RecommendationController::class, 'decide']);
        Route::get('/proactive-messages', [ProactiveMessageController::class, 'index']);
        Route::get('/proactive-messages/{proactiveMessage}', [ProactiveMessageController::class, 'show']);
        Route::resource('/recommendation', RecommendationController::class)->except(['edit', 'create', 'store']);
        Route::get('/booking/cancellation-requests', [BookingCancellationRequestController::class, 'index']);
        Route::post('/booking/{booking}/cancellation-request/approve', [BookingCancellationRequestController::class, 'approve']);
        Route::post('/booking/{booking}/cancellation-request/decline', [BookingCancellationRequestController::class, 'decline']);
        Route::post('/booking/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::post('/booking', [BookingController::class, 'store']);
        Route::get('/booking', [BookingController::class, 'index']);
        Route::get('/booking/{booking}', [BookingController::class, 'show']);
        Route::patch('/booking/{booking}', [BookingController::class, 'update']);
        Route::get('/analytics/conversion', [AnalyticsController::class, 'conversion']);
        Route::post('/transaction/import', [TransactionController::class, 'import']);
        Route::post('/transaction/{transaction}/reverse', [TransactionController::class, 'reverse']);
        Route::get('/transaction', [TransactionController::class, 'index']);
        Route::get('/transaction/{transaction}', [TransactionController::class, 'show']);
        Route::resource('/hotel', HotelController::class)->except(['edit', 'create']);
        Route::put('/room/{room}/housekeeping-status', [RoomHousekeepingController::class, 'update']);
        Route::post('/room/{room}/out-of-order', [RoomOutOfOrderController::class, 'store']);
        Route::patch('/room/{room}/out-of-order', [RoomOutOfOrderController::class, 'update']);
        Route::post('/room/{room}/return-to-service', [RoomOutOfOrderController::class, 'returnToService']);
        Route::get('/housekeeping/board', HousekeepingBoardController::class);
        Route::get('/maintenance/tasks', [MaintenanceTaskController::class, 'index']);
        Route::resource('/room', RoomController::class)->except(['edit', 'create']);
        Route::resource('/room-types', RoomTypeController::class)->except(['edit', 'create']);
        Route::put('/guest/{guest}/contact-preference', [GuestContactPreferenceController::class, 'update']);

        // What the AI remembers about a guest (SPEC-084). Written only by
        // extraction: no create or update routes.
        Route::get('/guest/{guest}/memories', [GuestMemoryController::class, 'index']);
        Route::delete('/guest/{guest}/memories/{memory}', [GuestMemoryController::class, 'destroy']);

        // Guest conversations, read-only (SPEC-082).
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::resource('/guest', GuestController::class)->except(['edit', 'create']);
        Route::resource('/hotel-policy', HotelPolicyController::class)->except(['edit', 'create', 'show']);
        Route::resource('/team', TeamController::class)->except(['edit', 'create']);
        Route::post('/team/{team}/members', [TeamController::class, 'addMember']);
        Route::resource('/task-category', TaskCategoryController::class)->except(['edit', 'create']);
        Route::post('/task/{task}/inspection', [TaskInspectionController::class, 'store']);
        Route::post('/task/{task}/issues', [TaskIssueController::class, 'store']);
        Route::resource('/task', TaskController::class)->except(['edit', 'create']);
        Route::resource('/users', UserController::class)->except(['edit', 'create']);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::resource('/staff-roles', StaffRoleController::class)->except(['edit', 'create']);
        Route::resource('/ai-insights', AiInsightsController::class)->except(['edit', 'create', 'show', 'update', 'destroy']);
        Route::resource('/knowledge-base-articles', KnowledgeBaseArticleController::class)->except(['edit', 'create']);
        // `deleted` before `{id}`, or it would be read as a document id.
        Route::get('/knowledge-documents/deleted', [KnowledgeDocumentController::class, 'deleted']);
        Route::get('/knowledge-documents', [KnowledgeDocumentController::class, 'index']);
        Route::post('/knowledge-documents', [KnowledgeDocumentController::class, 'store']);
        Route::get('/knowledge-documents/{id}', [KnowledgeDocumentController::class, 'show']);
        Route::put('/knowledge-documents/{id}', [KnowledgeDocumentController::class, 'update']);
        Route::delete('/knowledge-documents/{id}', [KnowledgeDocumentController::class, 'destroy']);
        Route::post('/knowledge-documents/{id}/file', [KnowledgeDocumentController::class, 'replace']);
        Route::get('/knowledge-documents/{id}/download', [KnowledgeDocumentController::class, 'download']);
        Route::get('/knowledge-documents/{id}/text', [KnowledgeDocumentController::class, 'showText']);
        Route::put('/knowledge-documents/{id}/text', [KnowledgeDocumentController::class, 'correctText']);
        Route::delete('/knowledge-documents/{id}/text', [KnowledgeDocumentController::class, 'discardText']);
        Route::post('/knowledge-documents/{id}/reindex', [KnowledgeDocumentController::class, 'reindex']);
        Route::post('/knowledge-documents/{id}/restore', [KnowledgeDocumentController::class, 'restore']);
        Route::post('/ai-advisor/chat', [AiAdvisorController::class, 'chat']);
        Route::get('/history/{type}/{id}', [HistoryController::class, 'show']);
        Route::get('/ai-activity', [AiActivityController::class, 'index']);

        // This account's own consumption. The account is resolved from the
        // token, so there is deliberately no id in the path — see
        // AccountUsageController. Cross-account reporting is elsewhere.
        Route::get('/usage', [AccountUsageController::class, 'index']);
    });
});
