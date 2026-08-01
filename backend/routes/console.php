<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 冪等紀錄只在過期之前有意義。採每小時而非每日執行，讓因過期而提早釋出的 key
// 能盡快重新被使用。
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();
