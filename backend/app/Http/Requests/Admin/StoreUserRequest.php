<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 先擋掉根本不能管理帳號的人（店員），避免他們拿到 422 驗證細節；
        // 針對「建立什麼角色／哪間門市」的細部權限由 Controller 內的 Policy 判斷。
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            // 總部管理員不隸屬門市；其餘角色必須指定門市
            'store_id' => [
                'nullable', 'integer', 'exists:stores,id',
                'required_unless:role,'.UserRole::HqAdmin->value,
                'prohibited_if:role,'.UserRole::HqAdmin->value,
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
