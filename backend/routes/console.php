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

// 入場閘門是第二個真相來源，它一定會跟資料庫漂移。這個排程就是承認那件事：
// 定期拿 MySQL 的數字把閘門校正回來，並且把漂移量記成指標。
//
// 五分鐘一次，比清理密集得多，因為漂移的其中一個方向（閘門比實際嚴格）是在
// 持續誤殺使用者 —— 而那件事不會有任何錯誤訊息，只會表現成「明明有位子卻報不
// 進去」。修正的延遲上限就是這個間隔。
//
// 同樣掛 onOneServer()，理由也一樣：對帳是冪等的（把閘門設成資料庫此刻的空位數），
// 雙跑最多是第二個節點發現「已經準了」。鎖只負責省掉重複的工作，不負責正確性。
Schedule::command('gate:reconcile')
    ->everyFiveMinutes()
    ->onOneServer();
