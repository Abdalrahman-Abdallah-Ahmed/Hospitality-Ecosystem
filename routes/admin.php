<?php

use App\Http\Controllers\Admin\AiCostController;
use App\Http\Controllers\Admin\KnowledgeBaseArticleController;
use App\Http\Controllers\Admin\KnowledgeDocumentController;
use App\Http\Controllers\Admin\KnowledgeRebuildController;
use App\Http\Controllers\Admin\UsageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin Routes
|--------------------------------------------------------------------------
|
| Cross-account reporting: these endpoints read every hotel group in the
| system, which is why they sit behind the super-admin role rather than a
| per-model policy — there is no single model to authorise against.
|
| The whole file is registered in bootstrap/app.php under:
|
|     api  →  api.key  →  auth:sanctum  →  throttle:api  →  tenant  →  super_admin
|
| and the URL prefix `api/admin`. Deliberately applied there rather than in a
| Route::group() here, so that EVERY route in this file is guarded by
| construction. A route added at the top level of this file cannot end up
| unprotected, which is the failure mode worth designing against in a file
| that exposes one customer's data to a request made by another.
|
| Do not add a tenant-facing endpoint here. A hotel-group admin looking at
| their own consumption is a different route, a different scope, and must not
| be able to see other accounts or our own cost of goods.
|
*/

// Cross-account super-admin reporting lives in routes/admin.php,
// registered in bootstrap/app.php with this same middleware stack
// plus `super_admin`.
Route::get('/usage', [UsageController::class, 'index']);
Route::get('/ai-cost', [AiCostController::class, 'index']);

// Global knowledge: what every hotel's assistant reads. It lives here, and
// only here, so nothing outside the super-admin stack can change it.
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
Route::get('/knowledge-base-articles', [KnowledgeBaseArticleController::class, 'index']);
Route::post('/knowledge-base-articles', [KnowledgeBaseArticleController::class, 'store']);
Route::get('/knowledge-base-articles/{id}', [KnowledgeBaseArticleController::class, 'show']);
Route::put('/knowledge-base-articles/{id}', [KnowledgeBaseArticleController::class, 'update']);
Route::delete('/knowledge-base-articles/{id}', [KnowledgeBaseArticleController::class, 'destroy']);
Route::post('/knowledge/rebuilds', [KnowledgeRebuildController::class, 'store']);
Route::get('/knowledge/rebuilds', [KnowledgeRebuildController::class, 'index']);
Route::get('/knowledge/rebuilds/{id}', [KnowledgeRebuildController::class, 'show']);
