<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported application locales.
     *
     * @var list<string>
     */
    protected array $supported = ['zh-TW', 'en'];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    /**
     * Pick the best supported locale for the request.
     *
     * Symfony normalises Accept-Language tags to underscores ("zh-TW" becomes
     * "zh_TW"), so matching is done on a normalised key and mapped back to the
     * hyphenated tag that translations are actually stored under. Falls back to
     * the first supported locale when the header matches nothing.
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
