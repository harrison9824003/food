<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每天凌晨補上「新進入開放期間」那一天的時段。指令本身是冪等的，
// withoutOverlapping 只是避免手動與排程同時跑時互相等鎖。
Schedule::command('slots:generate')->dailyAt('00:10')->withoutOverlapping();

// 釋放逾時未付款的暫時保留（釋放前會先向綠界對帳）。每分鐘一次，名額最多晚一分鐘歸還。
Schedule::command('reservations:release-expired')->everyMinute()->withoutOverlapping();

// Outbox：把訂位事件送去 ERP。每分鐘一次；單次最多跑 50 秒，避免下一輪排程啟動時上一輪還在跑。
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();

// 退款：補撿「已核准但還沒退成功」的（無法確認結果而排了重試的、佇列遺失的）。
Schedule::command('refunds:process')->everyMinute()->withoutOverlapping();

// 對帳補償：撿回因網路或當機而漏接的背景通知。
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
