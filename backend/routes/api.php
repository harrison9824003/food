<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessHourController;
use App\Http\Controllers\Admin\ClosedDateController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\SlotController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\MenuCategoryController;
use App\Http\Controllers\Admin\OutboxEventController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\MenuItemController;
use App\Http\Controllers\Admin\MenuItemImageController;
use App\Http\Controllers\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Customer\StoreController as CustomerStoreController;
use App\Http\Controllers\Customer\PhoneVerificationController;
use App\Http\Controllers\Dev\EcpaySimulatorController;
use App\Http\Controllers\Dev\SmsInboxController;
use App\Http\Controllers\Payments\EcpayNotifyController;
use App\Http\Controllers\Payments\EcpayReturnController;
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
    Route::get('stores/{store}/menu', [CustomerStoreController::class, 'menu']);
});

// 手機簡訊驗證：先寄驗證碼、再驗證換憑證；訂位、查詢、取消、付款都以這張憑證證明持有手機
Route::post('phone/send-code', [PhoneVerificationController::class, 'send'])->middleware('throttle:sms-send');
Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])->middleware('throttle:sms-verify');

Route::post('reservations', [CustomerReservationController::class, 'store'])->middleware('throttle:booking-create');

// 查詢、取消、付款都要猜「代號 + 手機」才能命中，限流擋暴力嘗試
Route::middleware('throttle:reservation-lookup')->group(function () {
    Route::get('my/reservations', [CustomerReservationController::class, 'mine']);
    Route::post('reservations/lookup', [CustomerReservationController::class, 'lookup']);
    Route::post('reservations/cancel', [CustomerReservationController::class, 'cancel']);
    Route::post('reservations/pay', [CustomerReservationController::class, 'pay']);
});

/*
|--------------------------------------------------------------------------
| 金流（綠界）
|--------------------------------------------------------------------------
|
| 這兩支由綠界的伺服器與顧客的瀏覽器呼叫，不能掛 IP 限流（綠界的來源 IP 很集中，重送時會被誤擋）。
| 安全性靠 CheckMacValue 驗章，而不是靠網址保密。
*/
Route::post('payments/ecpay/notify', EcpayNotifyController::class);
Route::match(['get', 'post'], 'payments/ecpay/return', EcpayReturnController::class);

// 本機付款模擬器：只在非正式環境且 ECPAY_SIMULATOR=true 時有作用，否則回 404
Route::post('dev/ecpay/simulate', EcpaySimulatorController::class)->middleware('throttle:30,1');
// 本機簡訊收件匣：看 MockSmsDriver 存下來的驗證碼簡訊；只在非正式環境且 SMS_MOCK_INBOX=true 時有作用
Route::get('dev/sms', SmsInboxController::class)->middleware('throttle:60,1');

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

            Route::get('menu-categories', [MenuCategoryController::class, 'index']);
            Route::post('menu-categories', [MenuCategoryController::class, 'store']);
            Route::patch('menu-categories/{menuCategory}', [MenuCategoryController::class, 'update']);
            Route::delete('menu-categories/{menuCategory}', [MenuCategoryController::class, 'destroy']);

            Route::get('menu-items', [MenuItemController::class, 'index']);
            Route::post('menu-items', [MenuItemController::class, 'store']);
            Route::patch('menu-items/{menuItem}', [MenuItemController::class, 'update']);
            Route::post('menu-items/{menuItem}/images', [MenuItemImageController::class, 'store']);
            Route::put('menu-items/{menuItem}/images/order', [MenuItemImageController::class, 'reorder']);
            Route::delete('menu-items/{menuItem}/images/{image}', [MenuItemImageController::class, 'destroy']);

            Route::get('slots', [SlotController::class, 'index']);
            Route::post('slots/generate', [SlotController::class, 'generate']);
            Route::patch('slots/{slot}', [SlotController::class, 'update']);
        });

        // ERP 同步：失敗清單與手動重送
        Route::get('outbox-events', [OutboxEventController::class, 'index']);
        Route::post('outbox-events/{outboxEvent}/retry', [OutboxEventController::class, 'retry']);

        // 退款簽核
        Route::get('refunds', [RefundController::class, 'index']);
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve']);
        Route::post('refunds/{refund}/reject', [RefundController::class, 'reject']);
        Route::post('refunds/{refund}/retry', [RefundController::class, 'retry']);

        Route::get('reservations', [AdminReservationController::class, 'index']);
        Route::patch('reservations/{reservation}/status', [AdminReservationController::class, 'updateStatus']);
    });
});
