<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * API 錯誤統一格式：{ "message": "...", "code": "SLOT_FULL", "errors": {} }
 */
class ApiErrorResponse
{
    public static function from(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::make($e->getMessage(), $e->errorCode, $e->status, $e->errors),
            $e instanceof ValidationException => self::make('資料驗證失敗', 'VALIDATION_FAILED', 422, $e->errors()),
            $e instanceof AuthenticationException => self::make('請先登入', 'UNAUTHENTICATED', 401),
            // Gate::authorize 拋出的 AuthorizationException 在進入 render callback 前已被轉成 AccessDenied
            $e instanceof AccessDeniedHttpException => self::make('沒有執行此操作的權限', 'FORBIDDEN', 403),
            $e instanceof NotFoundHttpException => self::make('找不到資源', 'NOT_FOUND', 404),
            $e instanceof TooManyRequestsHttpException => self::make('請求過於頻繁，請稍後再試', 'TOO_MANY_REQUESTS', 429, [], $e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::make($e->getMessage() ?: '請求失敗', 'HTTP_ERROR', $e->getStatusCode(), [], $e->getHeaders()),
            // 未預期錯誤：正式環境不外洩內部訊息，細節看日誌（以 request_id 查詢）
            default => self::make(config('app.debug') ? $e->getMessage() : '系統發生錯誤，請稍後再試', 'SERVER_ERROR', 500),
        };
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    private static function make(string $message, string $code, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
        ], $status, $headers);
    }
}
