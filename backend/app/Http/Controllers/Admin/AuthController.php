<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AdminAuthService $auth): JsonResponse
    {
        $result = $auth->login($request->validated('email'), $request->validated('password'));

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']->toIso8601String(),
            'user' => new UserResource($result['user']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // 只撤銷目前這支 token，不影響同一帳號在其他裝置的登入
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '已登出']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('store'));
    }
}
