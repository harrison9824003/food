<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessHourController;
use App\Http\Controllers\Admin\ClosedDateController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\SlotController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);

        Route::apiResource('stores', StoreController::class)->only(['index', 'show', 'store', 'update']);

        // scopeBindings：{diningTable}、{slot}、{closedDate} 會限定在 URL 上的 {store} 底下查詢，
        // 用別間門市的 ID 去打 /stores/1/tables/99 會得到 404，而不是改到別人的資料。
        Route::prefix('stores/{store}')->scopeBindings()->group(function () {
            Route::get('business-hours', [BusinessHourController::class, 'index']);
            Route::put('business-hours', [BusinessHourController::class, 'update']);

            Route::get('closed-dates', [ClosedDateController::class, 'index']);
            Route::post('closed-dates', [ClosedDateController::class, 'store']);
            Route::delete('closed-dates/{closedDate}', [ClosedDateController::class, 'destroy']);

            Route::get('tables', [DiningTableController::class, 'index']);
            Route::post('tables', [DiningTableController::class, 'store']);
            Route::patch('tables/{diningTable}', [DiningTableController::class, 'update']);

            Route::get('slots', [SlotController::class, 'index']);
            Route::post('slots/generate', [SlotController::class, 'generate']);
            Route::patch('slots/{slot}', [SlotController::class, 'update']);
        });
    });
});
