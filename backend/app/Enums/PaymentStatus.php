<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** 免訂金的訂位 */
    case NotRequired = 'not_required';
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunding = 'refunding';
    case Refunded = 'refunded';
}
