<?php

namespace App\Jobs;

use App\Sms\PhoneMask;
use App\Sms\SmsDriver;
use App\Sms\SmsException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 發送簡訊。走 Queue，不讓廠商的延遲或故障拖慢（或卡住）顧客的請求。
 *
 * ShouldBeEncrypted：job 的內容（含驗證碼）在 Redis 裡是加密的，不是明文。
 */
class SendSmsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 第一次加上三次重試。間隔見 backoff()。 */
    public int $tries = 4;

    /**
     * @param  string|null  $requestId  觸發這則簡訊的那次請求的 request_id。worker 是另一個行程，沒有請求脈絡，
     *                                  不帶過來的話，發送成功或失敗的日誌就沒辦法和顧客那次操作串起來。
     */
    public function __construct(
        public readonly string $phone,
        public readonly string $message,
        public readonly ?string $requestId = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return config('sms.retry_backoff');
    }

    public function handle(SmsDriver $driver): void
    {
        if ($this->requestId !== null) {
            Log::shareContext(['request_id' => $this->requestId]);
        }

        try {
            $driver->send($this->phone, $this->message);
        } catch (SmsException $e) {
            Log::channel('sms')->warning('sms send failed', [
                'phone' => PhoneMask::mask($this->phone), 'attempt' => $this->attempts(), 'error' => $e->getMessage(),
            ]);

            throw $e; // 交給 Queue 依 backoff 重試
        }

        Log::channel('sms')->info('sms sent', ['phone' => PhoneMask::mask($this->phone), 'attempt' => $this->attempts()]);
    }

    /** 重試用盡。顧客可以在冷卻時間過後重新要一組驗證碼。 */
    public function failed(Throwable $e): void
    {
        Log::channel('sms')->error('sms gave up after retries', [
            'phone' => PhoneMask::mask($this->phone), 'error' => $e->getMessage(),
        ]);
    }
}
