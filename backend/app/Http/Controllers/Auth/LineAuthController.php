<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class LineAuthController extends Controller
{
    /**
     * 回傳 LINE 的授權 URL，供前端把瀏覽器導向過去。
     */
    public function redirect(): JsonResponse
    {
        // LINE 要求一定要帶 `state` 參數，即使我們並不驗證它
        //（stateless 模式會略過以 CSRF／session 為基礎的 state 驗證）。
        $url = Socialite::driver('line')
            ->stateless()
            ->with(['state' => Str::random(40)])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * 處理 LINE 導回的請求，接著帶著 Sanctum token 把使用者送回前端，讓前端能以它
     * 驗證後續的 API 呼叫。
     */
    public function callback(): RedirectResponse
    {
        $lineUser = Socialite::driver('line')->stateless()->user();

        $user = User::where('line_id', $lineUser->getId())->first();

        if (! $user && $lineUser->getEmail()) {
            $user = User::where('email', $lineUser->getEmail())->first();
        }

        if (! $user) {
            $user = User::create([
                'name' => $lineUser->getName() ?? $lineUser->getNickname() ?? 'LINE User',
                'nickname' => $lineUser->getNickname(),
                'avatar' => $lineUser->getAvatar(),
                'email' => $lineUser->getEmail(),
                'line_id' => $lineUser->getId(),
                'password' => Str::password(32),
            ]);
        } elseif (! $user->line_id) {
            $user->update(['line_id' => $lineUser->getId()]);
        }

        $token = $user->createToken('line-login')->plainTextToken;

        return redirect(config('app.frontend_url') . '/auth/callback?token=' . $token);
    }
}
