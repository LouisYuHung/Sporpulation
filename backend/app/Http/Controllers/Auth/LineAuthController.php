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
     * Return the LINE authorize URL for the frontend to redirect the browser to.
     */
    public function redirect(): JsonResponse
    {
        $url = Socialite::driver('line')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Handle the redirect back from LINE, then send the user back to the
     * frontend with a Sanctum token so it can authenticate future API calls.
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
