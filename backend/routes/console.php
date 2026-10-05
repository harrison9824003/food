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

// 對帳補償：撿回因網路或當機而漏接的背景通知。
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
