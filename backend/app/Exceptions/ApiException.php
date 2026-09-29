<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 可預期的業務錯誤。由 ApiErrorResponse 轉成 { message, code, errors }。
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
