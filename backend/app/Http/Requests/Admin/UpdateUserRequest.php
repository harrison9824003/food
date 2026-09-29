<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:50'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'store_id' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * 角色與門市要一起看「修改後的最終狀態」：只送 role 或只送 store_id 也不能造成不合法組合。
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User $target */
                $target = $this->route('user');

                $role = $this->has('role') ? $this->input('role') : $target->role->value;
                $storeId = $this->has('store_id') ? $this->input('store_id') : $target->store_id;

                if ($role === UserRole::HqAdmin->value && $storeId !== null) {
                    $validator->errors()->add('store_id', '總部管理員不可隸屬門市');
                }

                if ($role !== UserRole::HqAdmin->value && $storeId === null) {
                    $validator->errors()->add('store_id', '此角色必須指定門市');
                }
            },
        ];
    }
}
