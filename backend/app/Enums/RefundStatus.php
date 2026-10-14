<?php

namespace App\Enums;

enum RefundStatus: string
{
    /** 已申請，等主管簽核 */
    case Requested = 'requested';
    /** 已核准，等待（或正在）向綠界退款 */
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    /** 綠界拒絕，或無法確認結果；需人工處理 */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => '待簽核',
            self::Approved => '退款處理中',
            self::Rejected => '已駁回',
            self::Completed => '已退款',
            self::Failed => '退款失敗',
        };
    }

    /** 「有效」的退款：同一筆付款同時只能有一筆（見 refunds.active_payment_id）。 */
    public function isActive(): bool
    {
        return $this !== self::Rejected;
    }
}
