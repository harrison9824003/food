<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        $users = User::query()
            ->with('store')
            // 店長只看得到自己門市；總部可用 ?store_id= 篩選
            ->when($actor->role === UserRole::StoreManager, fn ($q) => $q->where('store_id', $actor->store_id))
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->orderBy('id')
            ->paginate(20);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = new User($request->validated());

        Gate::authorize('create', $user);

        $user->save();

        return (new UserResource($user->load('store')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->fill($request->validated());

        // 此時 $user 已填入新值但未儲存，Policy 會同時比對修改前後的狀態
        Gate::authorize('update', $user);

        $wasDeactivated = $user->isDirty('is_active') && ! $user->is_active;

        $user->save();

        // 停用帳號要立即踢掉所有已發出的 token，不等它自然過期
        if ($wasDeactivated) {
            $user->tokens()->delete();
        }

        return new UserResource($user->load('store'));
    }
}
