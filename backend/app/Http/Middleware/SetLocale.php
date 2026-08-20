<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * 應用程式支援的語系。
     *
     * @var list<string>
     */
    protected array $supported = ['zh-TW', 'en'];

    /**
     * 處理傳入的請求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    /**
     * 為這個請求挑選最合適的支援語系。
     *
     * Symfony 會把 Accept-Language 標籤正規化成底線形式（「zh-TW」會變成
     * 「zh_TW」），因此比對時使用正規化後的鍵，再對應回翻譯檔實際採用的連字號
     * 標籤。當標頭比對不到任何語系時，退回第一個支援的語系。
     */
    private function resolve(Request $request): string
    {
        $canonical = [];

        foreach ($this->supported as $locale) {
            $canonical[$this->normalize($locale)] = $locale;
        }

        $preferred = (string) $request->getPreferredLanguage($this->supported);

        return $canonical[$this->normalize($preferred)] ?? $this->supported[0];
    }

    private function normalize(string $locale): string
    {
        return strtolower(str_replace('-', '_', $locale));
    }
}
