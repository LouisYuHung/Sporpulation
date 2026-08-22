<?php

namespace App\Idempotency;

/**
 * 記錄寫入結果的地方，讓重試能取回第一次的答案而不是執行第二次。
 *
 * 實作可以換掉「持久性」，但不能換掉「原子性」—— 見 claim()。這是整個介面存在
 * 的理由：讓呼叫端能選擇要付多少代價換多強的保證，而不必知道那是怎麼達成的。
 */
interface IdempotencyStore
{
    /**
     * 為這把 key 佔位；已被他人持有則回傳 false。
     *
     * 實作必須保證：並行呼叫中恰有一個回傳 true。這個保證不可協商 - 少了它，
     * 兩個重複的請求會同時抵達 controller，整個機制就沒有意義了。
     */
    public function claim(string $scope, string $key, string $fingerprint): bool;

    public function find(string $scope, string $key): ?IdempotencyRecord;

    /**
     * 寫入結果。不得延長這筆紀錄的過期時間 - 呼叫端等的是「第一次請求之後 24
     * 小時」，不是「最後一次寫入之後 24 小時」。
     */
    public function complete(string $scope, string $key, int $status, ?string $body, ?string $contentType): void;

    public function release(string $scope, string $key): void;
}
