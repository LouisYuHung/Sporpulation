<?php

namespace App\Http\Middleware;

use App\Exceptions\TooManyRequestsException;
use App\RateLimiting\SlidingWindowLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 擋在報名端點前面的第一層過濾。
 *
 * 它保護的不是正確性 —— 名額的正確性由 activities 那句條件式 UPDATE 保證，重複
 * 報名由 unique(activity_id, user_id) 保證。它保護的是「別讓重試風暴抵達資料庫」：
 * 一百個併發請求即使全部會被唯一鍵擋下，也還是一百次交易、一百次索引寫入。
 *
 * 因此它可以失效。Redis 掛掉時放行比拒絕服務好，後面兩層還在。
 */
class ThrottleRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $decision = SlidingWindowLimiter::fromConfig('registration')
                ->attempt($this->scope($request));
        } catch (Throwable $e) {
            // fail open：限流是第一層過濾，不是正確性保證。Redis 不可達時放行，
            // 名額的條件式 UPDATE 與 unique(activity_id, user_id) 仍然守著。
            // 為了拒絕服務而放棄可用性，在這一層是錯的取捨。
            report($e);

            return $next($request);
        }

        if (! $decision->allowed) {
            throw new TooManyRequestsException($decision->retryAfter);
        }

        $response = $next($request);

        // $next() 回的是 Symfony 的 Response，沒有 withHeaders()。
        $response->headers->set('X-RateLimit-Remaining', (string) $decision->remaining);

        return $response;
    }

    /**
     * 額度綁在誰身上。
     *
     * 以使用者為單位而非 IP：同一個 NAT 或公司網路後面的人不該互相消耗額度。
     * 未登入時退回 IP - 這條路由掛在 auth:sanctum 底下所以不會走到，但這個
     * middleware 不該假設呼叫端幫它保證了什麼。
     */
    private function scope(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
