<?php

namespace App\Providers;

use App\Erp\ErpGateway;
use App\Erp\HttpErpGateway;
use App\Payments\EcpayGateway;
use App\Payments\PaymentGateway;
use App\Sms\Every8dDriver;
use App\Sms\MockSmsDriver;
use App\Sms\SmsDriver;
use LogicException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 業務程式只依賴 PaymentGateway 介面；測試時可以換成替身或用 Http::fake()
        $this->app->bind(PaymentGateway::class, EcpayGateway::class);

        $this->app->bind(ErpGateway::class, HttpErpGateway::class);

        $this->app->bind(SmsDriver::class, function () {
            $driver = config('sms.driver');

            // Mock 不會真的發簡訊，正式環境若誤設成 mock，顧客永遠收不到驗證碼卻看不出錯誤，所以直接拒絕
            if ($driver === 'mock' && app()->isProduction()) {
                throw new LogicException('正式環境不可使用 mock 簡訊驅動');
            }

            return match ($driver) {
                'every8d' => new Every8dDriver,
                'mock' => new MockSmsDriver,
                default => throw new LogicException('不支援的簡訊驅動：'.$driver),
            };
        });
    }

    public function boot(): void
    {
        // 單筆資源直接回傳物件、不包 { data: ... }；分頁列表仍固定帶 data + meta + links
        JsonResource::withoutWrapping();

        // 後台登入：同一 email + IP 每分鐘 5 次，擋暴力破解。
        // key 同時含 IP：只用 email 的話，任何人都能用別人的 email 亂試來鎖住對方帳號。
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // 顧客端：以 IP 限流，擋機器人洗訂位與暴力猜訂位代號
        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(config('booking.create_rate_limit'))->by($request->ip()));
        // 寄驗證碼：同一手機與同一 IP 各自計數，任何一個超過就擋。
        // 只限制 IP 的話，攻擊者換 IP 就能灌爆同一支手機；只限制手機的話，攻擊者可以對大量不同的手機號碼狂發、刷爆簡訊費。
        RateLimiter::for('sms-send', fn (Request $request) => [
            Limit::perHour(config('sms.send_per_phone_per_hour'))->by('sms-phone:'.$request->input('phone')),
            Limit::perHour(config('sms.send_per_ip_per_hour'))->by('sms-ip:'.$request->ip()),
        ]);
        RateLimiter::for('sms-verify', fn (Request $request) => Limit::perMinutes(10, config('sms.verify_per_ip_per_10_minutes'))->by($request->ip()));

        RateLimiter::for('reservation-lookup', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(config('booking.public_read_rate_limit'))->by($request->ip()));
    }
}
