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
//
// onOneServer()：每個節點都跑著 schedule:work，沒有它的話清理會跑 N 次。底層是
// 一把 Redis cache lock（見 config/cache.php 的 lock_connection）—— 先搶到的節點
// 執行，其餘的直接跳過。
//
// 這裡容忍鎖偶爾失效：清理本身是冪等的（刪除已過期的列），雙跑最多是第二個節點
// 刪到 0 列。因此不需要一把完美的分散式鎖 —— 見 README 的取捨說明。
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])
    ->hourly()
    ->onOneServer();
