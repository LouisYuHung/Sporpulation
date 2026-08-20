<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * 請求被限流擋下。與 409 家族不同 - 那些代表「你輸了這場競爭」，這個代表
 * 「你根本沒被放行」，而且明確告訴呼叫端什麼時候可以再來。
 */
class TooManyRequestsException extends TooManyRequestsHttpException
{
    public function __construct(int $retryAfter)
    {
        // 第一個參數會被 Symfony 轉成 Retry-After 標頭。
        parent::__construct($retryAfter, __('messages.throttle.too_many'));
    }

    /**
     * 這個結果的固定識別碼，讓用戶端不必解析顯示文字就能分支處理 - 與
     * ConflictException::errorCode() 同一個約定。
     */
    public function errorCode(): string
    {
        return 'too_many_requests';
    }
}
