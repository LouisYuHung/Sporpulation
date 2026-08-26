<?php

namespace App\Http\Controllers;

use App\Metrics\MetricRegistry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;

class MetricsController extends Controller
{
    public function __invoke(MetricRegistry $metrics): Response
    {
        $this->registerLiveGauges($metrics);

        // Prometheus 用 Content-Type 判斷版本，寫錯它會拒收整份內容。
        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * 抓取當下才計算的量測值。
     *
     * 註冊在這裡而不是 ServiceProvider：這些值只有在被抓取時才有意義，讓每一個
     * 請求都去註冊它們是白費的。
     *
     * 積壓長度刻意「現場去問 Redis」，而不是入列 +1、處理完 -1。後者只要漏掉一次
     * 遞減（worker 被 kill、容器被回收）就永遠錯下去，而且錯得無法修正。這跟名額
     * 用 counter 而不用 COUNT(*) 剛好是相反方向的判斷：那裡的正確性靠條件式 UPDATE
     * 保證，漂移不可能發生；這裡沒有那種保證，所以選擇每次重新數。
     */
    private function registerLiveGauges(MetricRegistry $metrics): void
    {
        $connection = config('queue.connections.redis.connection', 'default');
        $queue = config('queue.connections.redis.queue', 'default');

        $metrics->gauge(
            'queue_backlog',
            '等待中的佇列工作數（不含延遲重試與處理中的）。',
            fn () => Redis::connection($connection)->llen('queues:'.$queue),
        );
    }
}
