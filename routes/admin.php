<?php

use App\Http\Controllers\Admin\AiCostController;
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
|     api  →  api.key  →  auth:sanctum  →  tenant  →  super_admin
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
