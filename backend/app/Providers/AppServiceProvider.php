<?php

namespace App\Providers;

use App\Payments\EcpayGateway;
use App\Payments\PaymentGateway;
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
        RateLimiter::for('reservation-lookup', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(config('booking.public_read_rate_limit'))->by($request->ip()));
    }
}
