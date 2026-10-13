<?php

namespace App\Sms;

use App\Exceptions\ApiException;
use App\Jobs\SendSmsJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OtpService
{
    /**
     * 寄出驗證碼。
     *
     * @throws ApiException 冷卻中
     */
    public function send(string $phone, ?string $ip): void
    {
        $cooldown = (int) config('sms.resend_cooldown_seconds');

        // Cache::add 是原子的（Redis SET NX）：同一手機同時送出兩個請求，只有一個搶得到冷卻鎖。
        // 若改成「先查上次寄送時間、再寫入」，兩個請求都會看到「冷卻已過」而各寄一則。
        if (! Cache::add($this->cooldownKey($phone), 1, $cooldown)) {
            $retryAfter = $this->retryAfter($phone, $cooldown);

            throw new ApiException("驗證碼剛寄出，請 {$retryAfter} 秒後再重新發送", 'SMS_COOLDOWN', 429, ['retry_after' => $retryAfter]);
        }

        $code = str_pad((string) random_int(0, 10 ** config('sms.code_length') - 1), config('sms.code_length'), '0', STR_PAD_LEFT);

        DB::transaction(function () use ($phone, $ip, $code) {
            // 舊的驗證碼一律作廢：同一時間只有最新寄出的那一組有效
            DB::table('sms_verifications')->where('phone', $phone)->whereNull('verified_at')->update(['expires_at' => now(), 'updated_at' => now()]);

            DB::table('sms_verifications')->insert([
                'phone' => $phone,
                'code_hash' => $this->hash($code),
                'ip' => $ip,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(config('sms.code_ttl_minutes')),
                'last_sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $minutes = config('sms.code_ttl_minutes');
        SendSmsJob::dispatch($phone, "【線上訂位】您的驗證碼是 {$code}，{$minutes} 分鐘內有效。請勿告訴任何人。", request()->header('X-Request-Id'));

        // 日誌只記遮罩後的手機號碼，絕不記驗證碼
        Log::channel('sms')->info('otp requested', ['phone' => PhoneMask::mask($phone), 'ip' => $ip]);
    }

    /**
     * 驗證。成功回傳憑證。
     *
     * 「沒有驗證碼」「過期」「打錯」一律回同樣的訊息，不洩漏這支手機有沒有要過驗證碼。
     *
     * @return array{token: string, expires_at: Carbon}
     *
     * @throws ApiException
     */
    public function verify(string $phone, string $code): array
    {
        $result = DB::transaction(function () use ($phone, $code) {
            // 鎖住這筆再判斷與計數：同時送出多個猜測時，不鎖的話每個都讀到 attempts=0，
            // 就能在一次尚未更新的空窗內猜超過上限的次數。
            $row = DB::table('sms_verifications')
                ->where('phone', $phone)->whereNull('verified_at')->where('expires_at', '>', now())
                ->orderByDesc('id')->lockForUpdate()->first();

            if ($row === null) {
                return 'invalid';
            }

            if ($row->attempts >= config('sms.max_attempts')) {
                return 'exhausted';
            }

            // 先計數再比對：猜錯固然要算，猜對也不會因為例外而漏算
            DB::table('sms_verifications')->where('id', $row->id)->increment('attempts');

            // hash_equals：固定時間比較，不會因為「前幾位相同就比較久」洩漏資訊
            if (! hash_equals($row->code_hash, $this->hash($code))) {
                return $row->attempts + 1 >= config('sms.max_attempts') ? 'exhausted' : 'invalid';
            }

            // 用過即作廢：同一組驗證碼不能換第二張憑證
            DB::table('sms_verifications')->where('id', $row->id)->update(['verified_at' => now(), 'updated_at' => now()]);

            return 'ok';
        });

        if ($result === 'exhausted') {
            Log::channel('sms')->warning('otp attempts exhausted', ['phone' => PhoneMask::mask($phone)]);
            throw new ApiException('錯誤次數過多，請重新取得驗證碼', 'CODE_ATTEMPTS_EXCEEDED', 429);
        }

        if ($result !== 'ok') {
            throw new ApiException('驗證碼不正確或已過期', 'INVALID_CODE', 422);
        }

        Log::channel('sms')->info('otp verified', ['phone' => PhoneMask::mask($phone)]);

        return PhoneToken::issue($phone);
    }

    /**
     * HMAC 而不是 bcrypt：驗證碼只有 6 位數，bcrypt 擋不住離線暴力破解（一百萬種可能，一下就試完），
     * 真正的防線是「5 次作廢」。用應用程式金鑰做 HMAC，資料庫外洩時沒有金鑰就無法反推，也不必為了每次驗證耗費 bcrypt 的時間。
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function cooldownKey(string $phone): string
    {
        return 'sms-cooldown:'.hash('sha256', $phone);
    }

    private function retryAfter(string $phone, int $cooldown): int
    {
        $last = DB::table('sms_verifications')->where('phone', $phone)->max('last_sent_at');

        // 直接用時間戳相減：Carbon 3 的 diffInSeconds 是有正負號的，方向寫反就會算成「冷卻 + 已過秒數」
        $elapsed = $last ? now()->timestamp - Carbon::parse($last)->timestamp : 0;

        return min($cooldown, max(1, $cooldown - $elapsed));
    }
}
