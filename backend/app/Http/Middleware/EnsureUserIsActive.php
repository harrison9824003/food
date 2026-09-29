<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 帳號被停用後，即使手上還有未過期的 token 也立即失效。
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            throw new ApiException('帳號已停用', 'ACCOUNT_DISABLED', 403);
        }

        return $next($request);
    }
}
