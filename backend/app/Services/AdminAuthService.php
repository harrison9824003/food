<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminAuthService
{
    private static ?string $dummyHash = null;

    /**
     * @return array{token: string, expires_at: Carbon, user: User}
     *
     * @throws ApiException
     */
    public function login(string $email, string $password): array
    {
        $user = User::with('store')->where('email', $email)->first();

        // 帳號不存在時仍做一次雜湊比對，讓「帳號不存在」與「密碼錯誤」的回應時間一致，
        // 避免有人靠回應時間探測哪些 email 是有效帳號。
        $hash = $user?->password ?? (self::$dummyHash ??= Hash::make(Str::random(32)));
        $passwordOk = Hash::check($password, $hash);

        if (! $user || ! $passwordOk) {
            Log::warning('admin login failed', ['email' => $email]);
            // 兩種失敗回同樣的訊息，不洩漏帳號是否存在
            throw new ApiException('帳號或密碼錯誤', 'INVALID_CREDENTIALS', 401);
        }

        // 密碼正確後才揭露停用狀態，避免無密碼者探測停用帳號
        if (! $user->is_active) {
            throw new ApiException('帳號已停用', 'ACCOUNT_DISABLED', 403);
        }

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('admin', ['*'], $expiresAt)->plainTextToken;

        return ['token' => $token, 'expires_at' => $expiresAt, 'user' => $user];
    }
}
