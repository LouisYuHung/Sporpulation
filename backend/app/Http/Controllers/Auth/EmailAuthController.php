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
 * Email and password sign-in, issuing the same kind of Sanctum token the LINE
 * flow hands out, so the frontend treats both logins identically.
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

        // One message for both "no such account" and "wrong password", so the
        // endpoint cannot be used to find out which emails are registered.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->tokenResponse($user, 'email-login');
    }

    /**
     * Revoke only the token that made this request, so signing out on one
     * device leaves the others alone.
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
