<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 為每個請求指派 request_id，寫入所有日誌並放進回應 header，
 * 讓一筆使用者回報的問題可以憑 request_id 串起整條日誌。
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        // 上游（nginx／前端）已帶 UUID 就沿用，方便跨服務追查；格式不對一律重發，避免日誌注入
        $incoming = $request->header(self::HEADER);
        $requestId = is_string($incoming) && Str::isUuid($incoming) ? $incoming : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
