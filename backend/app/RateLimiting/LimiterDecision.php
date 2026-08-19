<?php

namespace App\RateLimiting;

/**
 * 限流器對單一次嘗試做出的裁決。
 *
 * 三個欄位都是給 HTTP 層用的：allowed 決定要不要放行，remaining 可以回在
 * X-RateLimit-Remaining，retryAfter 則是 429 回應的 Retry-After 標頭。
 */
final readonly class LimiterDecision
{
    /**
     * @param  int  $remaining  這次之後還剩幾次額度；被擋下時為 0。
     * @param  int  $retryAfter  還要等幾秒才會有額度；放行時為 0。
     */
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfter,
    ) {}
}
