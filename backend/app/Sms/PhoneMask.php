<?php

namespace App\Sms;

class PhoneMask
{
    /** 日誌只留末三碼：夠對帳排錯，又不會把顧客的手機號碼整串寫進檔案。 */
    public static function mask(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 3)).substr($phone, -3);
    }
}
