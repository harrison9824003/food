<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessHourController;
use App\Http\Controllers\Admin\ClosedDateController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\SlotController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Customer\StoreController as CustomerStoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 顧客端（不需登入）
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:public-read')->group(function () {
    Route::get('stores', [CustomerStoreController::class, 'index']);
    Route::get('stores/{store}/calendar', [CustomerStoreController::class, 'calendar']);
    Route::get('stores/{store}/availability', [CustomerStoreController::class, 'availability']);
});

Route::post('reservations', [CustomerReservationController::class, 'store'])->middleware('throttle:booking-create');

// 查詢與取消要猜「代號 + 手機」才能命中，限流擋暴力嘗試
Route::middleware('throttle:reservation-lookup')->group(function () {
    Route::post('reservations/lookup', [CustomerReservationController::class, 'lookup']);
    Route::post('reservations/cancel', [CustomerReservationController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| 後台
|--------------------------------------------------------------------------
*/
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

        Route::get('reservations', [AdminReservationController::class, 'index']);
        Route::patch('reservations/{reservation}/status', [AdminReservationController::class, 'updateStatus']);
    });
});
