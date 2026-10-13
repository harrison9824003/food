<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SendCodeRequest;
use App\Http\Requests\Customer\VerifyCodeRequest;
use App\Sms\OtpService;
use Illuminate\Http\JsonResponse;

class PhoneVerificationController extends Controller
{
    public function send(SendCodeRequest $request, OtpService $otp): JsonResponse
    {
        $otp->send($request->validated('phone'), $request->ip());

        // 不論這支手機有沒有訂位、有沒有舊的驗證碼，回應都一樣
        return response()->json([
            'message' => '驗證碼已寄出',
            'resend_after' => (int) config('sms.resend_cooldown_seconds'),
            'expires_in' => config('sms.code_ttl_minutes') * 60,
        ]);
    }

    public function verify(VerifyCodeRequest $request, OtpService $otp): JsonResponse
    {
        $result = $otp->verify($request->validated('phone'), $request->validated('code'));

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
        ]);
    }
}
