<?php

namespace App\Exceptions;

/**
 * 另一個帶有相同冪等 key 的請求仍在執行中。用戶端應該稍候並以同一把 key 重試，
 * 而不是把這視為失敗。
 */
class RequestInProgressException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.idempotency.in_progress'));
    }

    public function errorCode(): string
    {
        return 'request_in_progress';
    }
}
