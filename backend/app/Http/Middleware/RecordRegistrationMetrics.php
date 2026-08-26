<?php

namespace App\Http\Middleware;

use App\Metrics\MetricRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 數報名的結局。
 *
 * 掛在整條路由鏈的最外層，因為那是唯一看得到全部結局的位置：限流的 429 被擋在
 * ThrottleRegistration 裡、冪等重播在 EnsureIdempotentRequest 就短路了，兩者都
 * 進不到 controller。
 *
 * 這裡讀的是「已經算出來的回應」而不是例外：Laravel 的路由管線會在每一層把內層
 * 丟出的例外轉成回應，所以外層 middleware 收到的是 429／409 的回應，不是例外。
 */
class RecordRegistrationMetrics
{
    public function __construct(private MetricRegistry $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->metrics->increment('registration_attempts_total', [
            'outcome' => $this->outcomeFor($response),
        ]);

        return $response;
    }

    /**
     * 結局的種類必須是固定的少數幾種。每一個相異的標籤值都是一條獨立的時間序列，
     * 所以這裡絕不能放狀態碼原文或錯誤訊息 —— 那會讓序列數量隨著錯誤種類無限成長。
     */
    private function outcomeFor(Response $response): string
    {
        // 重播和「真的搶到」的狀態碼一樣（都是 201），只有這個標頭分得出來。
        // 混在一起計會讓「成功報名數」被重試灌水。
        if ($response->headers->get('Idempotent-Replay') === 'true') {
            return 'replayed';
        }

        return match (true) {
            $response->isSuccessful() => 'granted',
            $response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS => 'throttled',
            $response->getStatusCode() === Response::HTTP_CONFLICT => 'rejected',
            $response->getStatusCode() >= 500 => 'error',
            default => 'invalid',
        };
    }
}
