<?php

namespace App\Erp;

use App\Models\OutboxEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpErpGateway implements ErpGateway
{
    public function send(OutboxEvent $event): void
    {
        try {
            $response = Http::timeout(config('erp.timeout'))
                ->acceptJson()
                // 冪等鍵：同一筆事件不論我們重送幾次，ERP 只處理一次。
                // 這是 at-least-once 投遞能安全運作的關鍵——「送出去但沒收到回應」時我們無從得知 ERP 有沒有處理，
                // 只能重送；沒有這個鍵，ERP 就會多出一筆重複的訂位。
                ->withHeaders(['Idempotency-Key' => self::idempotencyKey($event)])
                ->post(config('erp.url').'/events', [
                    'event_type' => $event->event_type,
                    'aggregate_type' => $event->aggregate_type,
                    'aggregate_id' => (string) $event->aggregate_id,
                    // 事件編號遞增，ERP 可以據此偵測亂序
                    'sequence' => $event->id,
                    'data' => $event->payload,
                ]);
        } catch (ConnectionException $e) {
            throw new ErpTemporaryException('無法連線到 ERP：'.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $detail = mb_substr(trim($response->body()), 0, 200);

        // 408 逾時、429 太頻繁、5xx 伺服器問題：過一陣子可能就好了。
        // 其他 4xx 是「我們送的東西有問題」：重試只會得到同樣的結果。
        if ($status === 408 || $status === 429 || $status >= 500) {
            throw new ErpTemporaryException("ERP 暫時無法處理（HTTP {$status}）{$detail}");
        }

        throw new ErpPermanentException("ERP 拒絕此事件（HTTP {$status}）{$detail}");
    }

    public static function idempotencyKey(OutboxEvent $event): string
    {
        return 'outbox-'.$event->id;
    }
}
