<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * 外部帶進來的 id 是不可信輸入，所以設上限。少了這個限制，任何人都能塞一個
     * 1MB 的標頭進來，讓這個請求的每一行 log 都膨脹 1MB。換行字元更糟 —— 那會讓
     * 一筆 log 在收集端看起來像好幾筆，也就是 log injection。
     */
    private const MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        // shareContext 讓這個請求之後的每一筆 log 都自動帶上 id，不必在每個
        // Log::info 的呼叫點手動傳。三個節點的 log 混進同一條 stream 之後，
        // 這是唯一能把「一個使用者動作」重新串回來的東西。
        Log::shareContext([
            'request_id' => $requestId,
            'node' => gethostname(),
        ]);

        // 寫回 request，讓後面的程式碼有需要時可以拿到同一個值。
        $request->headers->set(self::HEADER, $requestId);

        $response = $next($request);

        // 也回給用戶端：使用者回報問題時可以直接附上它。
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        // 只接受長得像 id 的字元。D 修飾子不能省 —— 少了它，PCRE 的 $ 也會匹配
        // 「結尾換行之前」，於是 "abc\n" 這種值會被當成合法 id 原樣寫進 log，
        // 收集端看到的就是兩筆而不是一筆。
        //
        // 不合格就當作沒帶、自己產一個 —— 這比拒絕整個請求好：追蹤用的識別碼
        // 壞掉，不應該讓報名失敗。
        if (preg_match('/^[A-Za-z0-9._-]{1,'.self::MAX_LENGTH.'}$/D', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
