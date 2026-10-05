<?php

namespace Tests\Feature\Booking;

use App\Models\MenuItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class MenuAndPreorderTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable();
        $this->other = Store::factory()->create();
    }

    private function item(string $name, int $price, bool $active = true, ?Store $store = null): MenuItem
    {
        return MenuItem::create(['store_id' => ($store ?? $this->store)->id, 'name' => $name, 'price' => $price, 'is_active' => $active]);
    }

    private function book(array $items, array $overrides = [])
    {
        return $this->postJson('/api/reservations', $this->bookingPayload($overrides + ['items' => $items]));
    }

    // ── 後台菜單管理 ────────────────────────────────────────────────────

    public function test_manager_manages_the_menu_of_their_own_store(): void
    {
        Sanctum::actingAs(User::factory()->storeManager($this->store)->create());
        $url = "/api/admin/stores/{$this->store->id}/menu-items";

        $id = $this->postJson($url, ['name' => '招牌牛排', 'price' => 680])
            ->assertCreated()->assertJsonPath('name', '招牌牛排')->assertJsonPath('price', 680)->assertJsonPath('is_active', true)
            ->json('id');

        $this->patchJson("{$url}/{$id}", ['price' => 720, 'is_active' => false])->assertOk()->assertJsonPath('price', 720)->assertJsonPath('is_active', false);
        $this->getJson($url)->assertOk()->assertJsonCount(1)->assertJsonPath('0.is_active', false);
    }

    public function test_menu_validation(): void
    {
        Sanctum::actingAs(User::factory()->storeManager($this->store)->create());
        $url = "/api/admin/stores/{$this->store->id}/menu-items";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['name', 'price'], 'errors');
        $this->postJson($url, ['name' => 'x', 'price' => -1])->assertStatus(422)->assertJsonValidationErrors('price', 'errors');
        $this->postJson($url, ['name' => 'x', 'price' => 'free'])->assertStatus(422)->assertJsonValidationErrors('price', 'errors');
    }

    public function test_staff_can_read_but_not_change_the_menu(): void
    {
        $item = $this->item('湯', 100);
        Sanctum::actingAs(User::factory()->staff($this->store)->create());
        $url = "/api/admin/stores/{$this->store->id}/menu-items";

        $this->getJson($url)->assertOk()->assertJsonCount(1);
        $this->postJson($url, ['name' => 'x', 'price' => 1])->assertStatus(403);
        $this->patchJson("{$url}/{$item->id}", ['price' => 1])->assertStatus(403);
    }

    public function test_menu_is_protected_across_stores(): void
    {
        $foreign = $this->item('別店的菜', 100, store: $this->other);
        Sanctum::actingAs(User::factory()->storeManager($this->store)->create());

        $this->getJson("/api/admin/stores/{$this->other->id}/menu-items")->assertStatus(403);
        $this->postJson("/api/admin/stores/{$this->other->id}/menu-items", ['name' => 'x', 'price' => 1])->assertStatus(403);
        // 用自己門市的網址去改別間的品項：綁定限定在該門市底下 → 404
        $this->patchJson("/api/admin/stores/{$this->store->id}/menu-items/{$foreign->id}", ['price' => 1])->assertStatus(404);
        $this->assertSame(100, $foreign->fresh()->price);
    }

    // ── 顧客端菜單 ──────────────────────────────────────────────────────

    public function test_customers_only_see_active_items_of_the_store(): void
    {
        $this->item('上架中', 100);
        $this->item('已下架', 200, active: false);
        $this->item('別店的菜', 300, store: $this->other);

        // 菜單依分類分組；沒有分類的品項歸在「其他」（分組與排序的細節見 Menu/MenuCatalogTest）
        $this->getJson("/api/stores/{$this->store->id}/menu")->assertOk()
            ->assertJsonCount(1, 'categories')
            ->assertJsonCount(1, 'categories.0.items')
            ->assertJsonPath('categories.0.items.0.name', '上架中');
    }

    // ── 訂位時預點 ──────────────────────────────────────────────────────

    public function test_preordered_items_are_saved_with_a_price_snapshot(): void
    {
        $steak = $this->item('牛排', 680);
        $soup = $this->item('濃湯', 120);

        $this->book([['menu_item_id' => $steak->id, 'quantity' => 2], ['menu_item_id' => $soup->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.name', '牛排')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.unit_price', 680);

        // 之後菜單改價，已成立的訂位不受影響
        $steak->update(['price' => 999]);
        $this->assertSame(680, \DB::table('reservation_items')->where('menu_item_id', $steak->id)->value('unit_price'));
    }

    public function test_preorder_is_optional_and_does_not_change_the_deposit(): void
    {
        $this->store->update(['deposit_per_person' => 100]);
        $item = $this->item('牛排', 680);

        $this->book([])->assertCreated()->assertJsonPath('deposit_amount', 200)->assertJsonCount(0, 'items');
        $this->book([['menu_item_id' => $item->id, 'quantity' => 5]], ['phone' => '0922111222'])
            ->assertCreated()->assertJsonPath('deposit_amount', 200); // 預點不計費，訂金仍是每人訂金 × 人數
    }

    public function test_unavailable_items_reject_the_whole_reservation(): void
    {
        $inactive = $this->item('已下架', 100, active: false);
        $foreign = $this->item('別店的菜', 100, store: $this->other);

        foreach ([$inactive->id, $foreign->id, 999999] as $id) {
            $this->book([['menu_item_id' => $id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'INVALID_MENU_ITEM');
        }

        $this->assertSame(0, $this->slot->fresh()->booked, '預點有問題時不可佔名額');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_preorder_validation(): void
    {
        $item = $this->item('牛排', 680);

        $this->book([['menu_item_id' => $item->id, 'quantity' => 0]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity', 'errors');
        $this->book([['menu_item_id' => $item->id, 'quantity' => 21]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity', 'errors');
        $this->book([['quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('items.0.menu_item_id', 'errors');
        // 同一品項重複出現
        $this->book([['menu_item_id' => $item->id, 'quantity' => 1], ['menu_item_id' => $item->id, 'quantity' => 2]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.menu_item_id', 'errors');
    }

    public function test_the_client_cannot_set_prices(): void
    {
        $item = $this->item('牛排', 680);

        // 就算前端多送了 unit_price，也只認伺服器上的價格
        $this->book([['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1]])
            ->assertCreated()->assertJsonPath('items.0.unit_price', 680);
    }

    public function test_admin_order_list_shows_the_preordered_items(): void
    {
        $item = $this->item('牛排', 680);
        $this->book([['menu_item_id' => $item->id, 'quantity' => 2]])->assertCreated();
        Sanctum::actingAs(User::factory()->staff($this->store)->create());

        $this->getJson('/api/admin/reservations')->assertOk()
            ->assertJsonPath('data.0.items.0.name', '牛排')
            ->assertJsonPath('data.0.items.0.quantity', 2);
    }

    public function test_customer_lookup_shows_the_preordered_items(): void
    {
        $item = $this->item('牛排', 680);
        $code = $this->book([['menu_item_id' => $item->id, 'quantity' => 1]])->json('code');

        $this->postJson('/api/reservations/lookup', ['code' => $code, 'phone' => '0912345678'])
            ->assertOk()->assertJsonPath('items.0.name', '牛排');
    }
}
