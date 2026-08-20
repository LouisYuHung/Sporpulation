<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Messages
    |--------------------------------------------------------------------------
    |
    | 由應用程式本身拋出的訊息(abort()、例外、API 回應)。
    | 每個 key 都要與 lang/en/messages.php 對應。
    |
    */

    'errors' => [
        'unauthenticated' => '尚未登入。',
        'forbidden' => '您沒有權限執行此操作。',
        'not_found' => '找不到請求的資源。',
        'server_error' => '發生錯誤,請稍後再試。',
    ],

    'auth' => [
        'logged_out' => '您已登出。',
    ],

    'sports' => [
        'not_tagged' => '這項運動不在您的清單中。',
    ],

    'activities' => [
        'full' => '這場活動的名額已滿。',
        'closed' => '這場活動已停止報名。',
    ],

    'idempotency' => [
        'in_progress' => '相同的請求正在處理中,請稍候再試。',
        'reused' => '這個冪等碼已經用於另一個不同的請求。',
        'invalid' => '冪等碼的長度不正確。',
    ],

    'throttle' => [
        'too_many' => '報名嘗試太過頻繁,請稍候再試。',
    ],

];
