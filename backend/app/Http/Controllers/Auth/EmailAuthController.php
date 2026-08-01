<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Email 與密碼登入，發放與 LINE 流程相同型式的 Sanctum token，讓前端可以用完全
 * 一致的方式處理這兩種登入。
 */
class EmailAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($data);

        return $this->tokenResponse($user, 'email-register')->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // 「查無此帳號」與「密碼錯誤」共用同一則訊息，避免這個端點被拿來探測
        // 哪些 email 已經註冊過。
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->tokenResponse($user, 'email-login');
    }

    /**
     * 只撤銷發出這個請求的 token，因此在某台裝置登出不會影響其他裝置。
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('messages.auth.logged_out')]);
    }

    private function tokenResponse(User $user, string $tokenName): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($tokenName)->plainTextToken,
            'user' => new UserResource($user->load(['areas.city', 'areas.postalCode', 'sports'])),
        ]);
    }
}
