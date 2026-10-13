<?php

namespace App\Erp;

use App\Models\OutboxEvent;

/**
 * 對外的 ERP／POS。業務程式只依賴這個介面，測試以 Http::fake() 取代。
 */
interface ErpGateway
{
    /**
     * @throws ErpTemporaryException 暫時性失敗（逾時、連線失敗、5xx、429）：值得重試
     * @throws ErpPermanentException 永久性失敗（4xx：資料被 ERP 拒絕）：重試也不會好，不要浪費重試次數
     */
    public function send(OutboxEvent $event): void;
}
