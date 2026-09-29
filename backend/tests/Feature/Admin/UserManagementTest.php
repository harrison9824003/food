<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 帳號管理的權限矩陣，逐格驗證（見 docs/permissions.md）。
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();
    }

    private function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '新同事',
            'email' => 'new@example.com',
            'password' => 'secret-123',
            'role' => 'staff',
            'store_id' => $this->storeA->id,
        ], $overrides);
    }

    // ── 店員：完全不可管理 ──────────────────────────────────────────────

    public function test_staff_cannot_list_or_create_or_update_users(): void
    {
        $this->actingAsUser(User::factory()->staff($this->storeA)->create());
        $other = User::factory()->staff($this->storeA)->create();

        $this->getJson('/api/admin/users')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson('/api/admin/users', $this->payload())->assertStatus(403);
        $this->patchJson("/api/admin/users/{$other->id}", ['name' => 'x'])->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertNotSame('x', $other->fresh()->name);
    }

    // ── 列表範圍 ────────────────────────────────────────────────────────

    public function test_hq_admin_sees_all_users_and_can_filter_by_store(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        User::factory()->staff($this->storeA)->create();
        User::factory()->staff($this->storeB)->create();

        $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(3, 'data');

        $this->getJson("/api/admin/users?store_id={$this->storeB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.store_id', $this->storeB->id);
    }

    public function test_store_manager_only_sees_own_store_users(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        User::factory()->staff($this->storeA)->create();
        User::factory()->staff($this->storeB)->create();

        $response = $this->getJson('/api/admin/users')->assertOk();

        $this->assertSame([$this->storeA->id], collect($response->json('data'))->pluck('store_id')->unique()->all());
        $response->assertJsonCount(2, 'data');
    }

    public function test_store_manager_cannot_bypass_scope_with_store_id_filter(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        User::factory()->staff($this->storeB)->create();

        $this->getJson("/api/admin/users?store_id={$this->storeB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── 建立 ────────────────────────────────────────────────────────────

    public function test_hq_admin_can_create_any_role_in_any_store(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->postJson('/api/admin/users', $this->payload(['email' => 'a@example.com', 'role' => 'store_manager', 'store_id' => $this->storeB->id]))
            ->assertCreated()
            ->assertJsonPath('role', 'store_manager')
            ->assertJsonPath('store.id', $this->storeB->id);

        $this->postJson('/api/admin/users', $this->payload(['email' => 'b@example.com', 'role' => 'hq_admin', 'store_id' => null]))
            ->assertCreated()
            ->assertJsonPath('store_id', null);
    }

    public function test_store_manager_can_create_staff_only_in_own_store(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());

        $this->postJson('/api/admin/users', $this->payload())->assertCreated();

        $this->postJson('/api/admin/users', $this->payload(['email' => 'x@example.com', 'store_id' => $this->storeB->id]))
            ->assertStatus(403);
    }

    public function test_store_manager_cannot_create_manager_or_hq_admin(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());

        $this->postJson('/api/admin/users', $this->payload(['role' => 'store_manager']))->assertStatus(403);
        $this->postJson('/api/admin/users', $this->payload(['role' => 'hq_admin', 'store_id' => null]))->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_create_validates_role_store_combination(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        // 總部管理員不可指定門市
        $this->postJson('/api/admin/users', $this->payload(['role' => 'hq_admin']))
            ->assertStatus(422)->assertJsonValidationErrors('store_id', 'errors');

        // 其他角色必須指定門市
        $this->postJson('/api/admin/users', $this->payload(['store_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('store_id', 'errors');

        // 不存在的門市
        $this->postJson('/api/admin/users', $this->payload(['store_id' => 99999]))
            ->assertStatus(422)->assertJsonValidationErrors('store_id', 'errors');
    }

    public function test_create_rejects_duplicate_email_and_weak_password(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/admin/users', $this->payload(['email' => 'dup@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
        $this->postJson('/api/admin/users', $this->payload(['password' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password', 'errors');
    }

    public function test_created_user_password_is_hashed_and_can_login(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->postJson('/api/admin/users', $this->payload())->assertCreated();

        $this->assertNotSame('secret-123', User::where('email', 'new@example.com')->value('password'));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => 'new@example.com', 'password' => 'secret-123'])->assertOk();
    }

    // ── 更新 ────────────────────────────────────────────────────────────

    public function test_hq_admin_can_change_role_and_store(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $target = User::factory()->staff($this->storeA)->create();

        $this->patchJson("/api/admin/users/{$target->id}", ['role' => 'store_manager', 'store_id' => $this->storeB->id])
            ->assertOk()
            ->assertJsonPath('role', 'store_manager')
            ->assertJsonPath('store_id', $this->storeB->id);
    }

    public function test_store_manager_can_edit_own_store_staff(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $target = User::factory()->staff($this->storeA)->create();

        $this->patchJson("/api/admin/users/{$target->id}", ['name' => '改名字'])
            ->assertOk()
            ->assertJsonPath('name', '改名字');
    }

    public function test_store_manager_cannot_edit_other_store_staff(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $target = User::factory()->staff($this->storeB)->create();

        $this->patchJson("/api/admin/users/{$target->id}", ['name' => '偷改'])->assertStatus(403);
        $this->assertNotSame('偷改', $target->fresh()->name);
    }

    public function test_store_manager_cannot_promote_staff_or_move_them_to_another_store(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $target = User::factory()->staff($this->storeA)->create();

        // 越權升級
        $this->patchJson("/api/admin/users/{$target->id}", ['role' => 'store_manager'])->assertStatus(403);
        // 把自己店的人調去別間店
        $this->patchJson("/api/admin/users/{$target->id}", ['store_id' => $this->storeB->id])->assertStatus(403);

        $fresh = $target->fresh();
        $this->assertSame('staff', $fresh->role->value);
        $this->assertSame($this->storeA->id, $fresh->store_id);
    }

    public function test_store_manager_cannot_pull_other_store_staff_into_own_store(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $target = User::factory()->staff($this->storeB)->create();

        $this->patchJson("/api/admin/users/{$target->id}", ['store_id' => $this->storeA->id])->assertStatus(403);
    }

    public function test_store_manager_cannot_edit_peers_or_hq(): void
    {
        $manager = $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $peer = User::factory()->storeManager($this->storeA)->create();
        $hq = User::factory()->hqAdmin()->create();

        $this->patchJson("/api/admin/users/{$peer->id}", ['name' => 'x'])->assertStatus(403);
        $this->patchJson("/api/admin/users/{$hq->id}", ['name' => 'x'])->assertStatus(403);
        $this->patchJson("/api/admin/users/{$manager->id}", ['name' => 'x'])->assertStatus(403);
    }

    public function test_update_validates_final_role_store_combination(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $target = User::factory()->staff($this->storeA)->create();

        // 只改 role 成 hq_admin，但原本仍掛著門市
        $this->patchJson("/api/admin/users/{$target->id}", ['role' => 'hq_admin'])
            ->assertStatus(422)->assertJsonValidationErrors('store_id', 'errors');

        // 把門市拿掉但角色仍是店員
        $this->patchJson("/api/admin/users/{$target->id}", ['store_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('store_id', 'errors');
    }

    public function test_update_email_uniqueness_ignores_self(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $target = User::factory()->staff($this->storeA)->create(['email' => 'me@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->patchJson("/api/admin/users/{$target->id}", ['email' => 'me@example.com', 'name' => 'ok'])->assertOk();
        $this->patchJson("/api/admin/users/{$target->id}", ['email' => 'taken@example.com'])->assertStatus(422);
    }

    // ── 停用 ────────────────────────────────────────────────────────────

    public function test_deactivating_a_user_revokes_their_tokens_and_blocks_login(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $target = User::factory()->staff($this->storeA)->create(['email' => 'leaving@example.com']);
        $target->createToken('admin');
        $this->assertSame(1, $target->tokens()->count());

        $this->patchJson("/api/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertSame(0, $target->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => 'leaving@example.com', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_store_manager_can_deactivate_own_store_staff(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $target = User::factory()->staff($this->storeA)->create();

        $this->patchJson("/api/admin/users/{$target->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_hq_admin_cannot_lock_themselves_out(): void
    {
        $hq = $this->actingAsUser(User::factory()->hqAdmin()->create());

        // 停用自己
        $this->patchJson("/api/admin/users/{$hq->id}", ['is_active' => false])->assertStatus(403);
        // 把自己降級
        $this->patchJson("/api/admin/users/{$hq->id}", ['role' => 'staff', 'store_id' => $this->storeA->id])->assertStatus(403);

        $fresh = $hq->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('hq_admin', $fresh->role->value);
    }

    public function test_hq_admin_can_still_edit_own_profile(): void
    {
        $hq = $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->patchJson("/api/admin/users/{$hq->id}", ['name' => '新名字'])->assertOk();
    }
}
