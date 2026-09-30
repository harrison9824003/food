<?php

namespace Tests\Feature\Admin;

use App\Models\Slot;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoreSchedule;
use Tests\TestCase;

/**
 * 門市本身的權限矩陣（見 docs/permissions.md）。
 */
class StoreManagementTest extends TestCase
{
    use CreatesStoreSchedule;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeToday();
        $this->storeA = Store::factory()->create(['advance_days' => 2]);
        $this->storeB = Store::factory()->create(['advance_days' => 2]);
    }

    private function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_hq_admin_sees_all_stores(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->getJson('/api/admin/stores')->assertOk()->assertJsonCount(2);
    }

    public function test_manager_and_staff_only_see_their_own_store(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $this->getJson('/api/admin/stores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->storeA->id);

        $this->actingAsUser(User::factory()->staff($this->storeB)->create());
        $this->getJson('/api/admin/stores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->storeB->id);
    }

    public function test_show_is_limited_to_own_store_for_non_hq(): void
    {
        $this->actingAsUser(User::factory()->staff($this->storeA)->create());

        $this->getJson("/api/admin/stores/{$this->storeA->id}")->assertOk()->assertJsonPath('name', $this->storeA->name);
        $this->getJson("/api/admin/stores/{$this->storeB->id}")->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_only_hq_admin_can_create_stores(): void
    {
        $payload = ['name' => '新竹店', 'advance_days' => 14, 'deposit_per_person' => 150];

        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $this->postJson('/api/admin/stores', $payload)->assertStatus(403);
        $this->assertDatabaseMissing('stores', ['name' => '新竹店']);

        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $this->postJson('/api/admin/stores', $payload)
            ->assertCreated()
            ->assertJsonPath('name', '新竹店')
            ->assertJsonPath('advance_days', 14)
            ->assertJsonPath('deposit_per_person', 150);
    }

    public function test_create_validates_input(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->postJson('/api/admin/stores', [])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
        $this->postJson('/api/admin/stores', ['name' => $this->storeA->name])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
        $this->postJson('/api/admin/stores', ['name' => 'X', 'advance_days' => 0])->assertStatus(422)->assertJsonValidationErrors('advance_days', 'errors');
        $this->postJson('/api/admin/stores', ['name' => 'X', 'deposit_per_person' => -1])->assertStatus(422)->assertJsonValidationErrors('deposit_per_person', 'errors');
    }

    public function test_hq_admin_can_update_every_field_including_deactivation(): void
    {
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->patchJson("/api/admin/stores/{$this->storeA->id}", [
            'name' => '改名店', 'deposit_per_person' => 300, 'is_active' => false, 'cutoff_minutes' => 90,
        ])->assertOk()->assertJsonPath('name', '改名店')->assertJsonPath('is_active', false);
    }

    public function test_manager_can_update_opening_rules_of_own_store(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());

        $this->patchJson("/api/admin/stores/{$this->storeA->id}", [
            'address' => '新地址', 'phone' => '0223456789', 'advance_days' => 10, 'cutoff_minutes' => 120, 'slot_capacity_default' => 30,
        ])->assertOk()
            ->assertJsonPath('address', '新地址')
            ->assertJsonPath('advance_days', 10)
            ->assertJsonPath('slot_capacity_default', 30);
    }

    public function test_manager_cannot_change_hq_only_fields(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $originalName = $this->storeA->name;

        foreach ([['name' => '偷改名'], ['deposit_per_person' => 1], ['is_active' => false]] as $payload) {
            $this->patchJson("/api/admin/stores/{$this->storeA->id}", $payload)
                ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        }

        $fresh = $this->storeA->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertTrue($fresh->is_active);
    }

    public function test_manager_cannot_update_other_store_and_staff_cannot_update_at_all(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());
        $this->patchJson("/api/admin/stores/{$this->storeB->id}", ['address' => 'x'])->assertStatus(403);

        $this->actingAsUser(User::factory()->staff($this->storeA)->create());
        $this->patchJson("/api/admin/stores/{$this->storeA->id}", ['address' => 'x'])->assertStatus(403);
    }

    public function test_extending_advance_days_creates_new_slots_immediately(): void
    {
        $this->openAllWeek($this->storeA, '11:00', '12:00', 60); // 每天只有 11:00 一個時段
        $this->actingAsUser(User::factory()->storeManager($this->storeA)->create());

        $this->patchJson("/api/admin/stores/{$this->storeA->id}", ['advance_days' => 4])->assertOk();

        // 今天 + 4 天 = 5 天
        $this->assertSame(5, Slot::where('store_id', $this->storeA->id)->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/stores')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
