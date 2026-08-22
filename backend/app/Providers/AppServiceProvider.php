<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
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
    }
}
