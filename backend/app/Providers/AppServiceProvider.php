<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Line\LineExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 註冊應用程式服務。
     */
    public function register(): void
    {
        // 介面不能被實例化，要告訴容器用哪個實作。目前只有一種；Step 4 會換成依路由
        // 選擇 store 的工廠。

    }

    /**
     * 啟動應用程式服務。
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, LineExtendSocialite::class);

        $this->traceJobsAcrossTheQueue();
    }

    /**
     * 讓 request id 跟著 Job 穿過 Redis，抵達另一個容器。
     */
    private function traceJobsAcrossTheQueue(): void
    {
        // 派送端：寫進 payload。掛在這個全域鉤子上而不是讓每個 Job 自己記，是因為
        // 「記得帶」這種事只要有一個 Job 忘記，追蹤鏈就從那裡開始斷 —— 而且是安靜地斷。
        Queue::createPayloadUsing(fn () => [
            'request_id' => Log::sharedContext()['request_id'] ?? null,
        ]);

        // 消費端：讀回來，接回這個行程的 log context。
        Queue::before(function (JobProcessing $event) {
            // worker 是長壽行程，一個行程會處理成千上萬個 Job，而 shared context
            // 不會自己消失。
            //
            // 下面三個鍵每次都無條件覆寫，所以就這三個而言 flush 其實是多餘的
            // （我實測拿掉沒有任何差別）。它防的是「不對稱的鍵」：某個 Job 在
            // handle() 裡自己 shareContext 了 order_id，下一個 Job 沒有這個鍵，
            // 那個值就會殘留下去。錯誤的關聯比沒有關聯更危險 —— 你會很有信心地
            // 查錯方向。
            Log::flushSharedContext();

            Log::shareContext([
                'request_id' => $event->job->payload()['request_id'] ?? null,
                'job_uuid' => $event->job->uuid(),
                'node' => gethostname(),
            ]);
        });

    }
}
