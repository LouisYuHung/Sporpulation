<?php

use App\Idempotency\DatabaseIdempotencyStore;
use App\Idempotency\RedisIdempotencyStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency Keys
    |--------------------------------------------------------------------------
    |
    | A client that retries a write after a timeout has no way of knowing
    | whether the first attempt landed. Sending the same key on the retry lets
    | the server recognise it and replay the original response instead of
    | acting twice.
    |
    | 紀錄依路由存放在兩種後端之一（見 routes/api.php）。資料庫是預設值，也是安全
    | 的方向：沒有天然唯一鍵可以退而求其次的寫入 —— 建立活動 —— 完全靠冪等碼，
    | 因此它的紀錄不能放在會被例行清除或驅逐的地方。
    |
    | Redis 留給「已經有第二層防線」的寫入：報名有 unique(activity_id, user_id)
    | 守著，紀錄遺失只會讓重播退化成重新執行，而不會留下重複資料。差別不在速度，
    | 在於「紀錄消失時，是誰在兜底」。
    |
    */

    'header' => 'Idempotency-Key',

    /*
    | How long a key is remembered. Long enough to cover any retry a client
    | would reasonably make, short enough that keys do not accumulate forever.
    */
    'ttl' => 24 * 60 * 60,

    /*
    | Bounds on the key itself, so a client cannot fill the table with one
    | enormous key or collide by sending something trivially short.
    */
    'min_length' => 8,
    'max_length' => 255,

    /*
    |--------------------------------------------------------------------------
    | 後端
    |--------------------------------------------------------------------------
    |
    | 沒有明確指定時用哪一個。預設是 database - 安全的方向：新加的路由如果忘了想
    | 這件事，錯的方向應該是「保護太強」而不是「保護會無聲消失」。
    |
    */
    'default' => env('IDEMPOTENCY_STORE', 'database'),

    'stores' => [
        'database' => DatabaseIdempotencyStore::class,
        'redis' => RedisIdempotencyStore::class,
    ],

    /*
    | Redis 後端的位置。獨立的連線（db 2）而不是 cache 連線（db 1）—— Cache::flush()
    | 對 Redis 的實作是 FLUSHDB，共用的話 cache:clear 會連冪等紀錄一起清掉。
    */
    'redis' => [
        'connection' => 'idempotency',
        'prefix' => 'idem:',
    ],

];
