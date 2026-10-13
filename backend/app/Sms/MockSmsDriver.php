<?php

namespace App\Sms;

use Illuminate\Support\Facades\DB;

class MockSmsDriver implements SmsDriver
{
    public function send(string $phone, string $message): void
    {
        DB::table('mock_sms_messages')->insert(['phone' => $phone, 'body' => $message, 'created_at' => now()]);
    }
}
