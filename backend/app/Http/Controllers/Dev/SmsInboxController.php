<?php

namespace App\Http\Controllers\Dev;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 開發用簡訊收件匣：本機不發真的簡訊，從這裡看 MockSmsDriver 存下來的內容。
 * 只在非正式環境、且明確開啟（SMS_MOCK_INBOX=true）時可用，否則回 404。
 */
class SmsInboxController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('sms.inbox_enabled') || app()->isProduction()) {
            throw new ApiException('找不到資源', 'NOT_FOUND', 404);
        }

        $request->validate(['phone' => ['required', 'string', 'regex:/^09\d{8}$/']]);

        return response()->json(
            DB::table('mock_sms_messages')->where('phone', $request->query('phone'))->orderByDesc('id')->limit(5)->get(['body', 'created_at'])
        );
    }
}
