<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * 預設為隸屬某門市的店員。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // 不用 safeEmail()：APP_FAKER_LOCALE=zh_TW 時，中文姓名無法轉成英文帳號會直接拋例外
            'email' => strtolower(Str::random(12)).'@example.com',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Staff,
            'store_id' => Store::factory(),
            'is_active' => true,
        ];
    }

    public function hqAdmin(): static
    {
        return $this->state(fn () => ['role' => UserRole::HqAdmin, 'store_id' => null]);
    }

    public function storeManager(?Store $store = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::StoreManager,
            'store_id' => $store?->id ?? Store::factory(),
        ]);
    }

    public function staff(?Store $store = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Staff,
            'store_id' => $store?->id ?? Store::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
