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
        //
    }

    /**
     * 啟動應用程式服務。
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, LineExtendSocialite::class);
    }
}
