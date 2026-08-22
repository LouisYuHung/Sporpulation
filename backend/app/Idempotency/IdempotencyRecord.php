<?php

namespace App\Idempotency;

/**
 * 一筆已被記錄下來的寫入結果，與它存在哪裡無關。
 *
 * 刻意不是 Eloquent 模型：介面要能換掉後端，就不能讓 Eloquent 從回傳值漏出去 -
 * Redis 那一版沒有模型可以回。
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $fingerprint,
        public ?int $status,
        public ?string $body,
        public ?string $contentType,
    ) {}

    /**
     * 沒有 status 的紀錄，代表這是一個請求尚未完成的佔位。
     */
    public function isInProgress(): bool
    {
        return $this->status === null;
    }
}
