<?php

namespace App\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    /** 超過重試上限，進入後台「同步失敗清單」等待手動重送。 */
    case Failed = 'failed';
}
