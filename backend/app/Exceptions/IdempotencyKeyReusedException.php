<?php

namespace App\Exceptions;

/**
 * 這把 key 已經被用在另一個不同的請求上。重播已儲存的回應等於回答了用戶端沒問過
 * 的問題，因此直接拒絕 - 這種情況一定代表用戶端產生 key 的方式有錯。
 */
class IdempotencyKeyReusedException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.idempotency.reused'));
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }
}
