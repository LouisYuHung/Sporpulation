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
    | Records live in the idempotency_keys table, not the cache: the cache gets
    | cleared as a matter of routine, and losing these would remove the
    | protection without anything failing loudly. Expired rows are pruned on a
    | schedule (see routes/console.php).
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
