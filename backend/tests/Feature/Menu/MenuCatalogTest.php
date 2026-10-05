<?php

namespace Tests\Feature\Menu;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

/**
 * 顧客看到的菜單（分組、售完、下架）以及預點時的規則。
 */
class MenuCatalogTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->setUpBookable();
    }

    private function category(string $name, int $order = 0, bool $active = true): MenuCategory
    {
        return MenuCategory::create(['store_id' => $this->store->id, 'name' => $name, 'sort_order' => $order, 'is_active' => $active]);
    }

    private function item(string $name, ?MenuCategory $category = null, array $attributes = []): MenuItem
    {
        return MenuItem::create($attributes + [
            'store_id' => $this->store->id, 'category_id' => $category?->id, 'name' => $name, 'price' => 100,
        ]);
    }

    private function menu()
    {
        return $this->getJson("/api/stores/{$this->store->id}/menu");
    }

    // ── 顧客看到的菜單 ──────────────────────────────────────────────────

    public function test_menu_is_grouped_by_category_in_sort_order(): void
    {
        $dessert = $this->category('甜點', 2);
        $main = $this->category('主餐', 1);
        $this->item('布丁', $dessert);
        $this->item('牛排', $main, ['sort_order' => 2]);
        $this->item('燉飯', $main, ['sort_order' => 1]);

        $response = $this->menu()->assertOk();

        $this->assertSame(['主餐', '甜點'], collect($response->json('categories'))->pluck('name')->all());
        $this->assertSame(['燉飯', '牛排'], collect($response->json('categories.0.items'))->pluck('name')->all(), '分類內依 sort_order 排序');
    }

    public function test_uncategorized_items_appear_last_under_other(): void
    {
        $main = $this->category('主餐');
        $this->item('牛排', $main);
        $this->item('招待小菜');

        $response = $this->menu()->assertOk();

        $this->assertSame(['主餐', '其他'], collect($response->json('categories'))->pluck('name')->all());
        $this->assertNull($response->json('categories.1.id'));
        $this->assertSame('招待小菜', $response->json('categories.1.items.0.name'));
    }

    public function test_inactive_items_and_inactive_categories_are_hidden(): void
    {
        $main = $this->category('主餐');
        $hidden = $this->category('停用的分類', 5, active: false);
        $this->item('牛排', $main);
        $this->item('已下架的菜', $main, ['is_active' => false]);
        $this->item('停用分類裡的菜', $hidden);

        $response = $this->menu()->assertOk();

        $this->assertCount(1, $response->json('categories'));
        $this->assertSame(['牛排'], collect($response->json('categories.0.items'))->pluck('name')->all());
    }

    public function test_categories_without_visible_items_are_not_shown(): void
    {
        $this->category('空的分類');

        $this->menu()->assertOk()->assertJsonCount(0, 'categories');
    }

    public function test_sold_out_items_are_still_listed_but_flagged(): void
    {
        $main = $this->category('主餐');
        $this->item('牛排', $main, ['is_sold_out' => true]);

        $this->menu()->assertOk()
            ->assertJsonPath('categories.0.items.0.name', '牛排')
            ->assertJsonPath('categories.0.items.0.is_sold_out', true);
    }

    public function test_items_carry_description_price_and_images_in_order(): void
    {
        $item = $this->item('牛排', $this->category('主餐'), ['description' => '200g 牛肋', 'price' => 680]);
        foreach ([2 => 'second', 1 => 'first'] as $order => $name) {
            MenuItemImage::create([
                'menu_item_id' => $item->id, 'path' => "menu/x/{$name}.webp", 'thumb_path' => "menu/x/{$name}_thumb.webp",
                'width' => 800, 'height' => 600, 'sort_order' => $order,
            ]);
        }

        $response = $this->menu()->assertOk()
            ->assertJsonPath('categories.0.items.0.description', '200g 牛肋')
            ->assertJsonPath('categories.0.items.0.price', 680)
            ->assertJsonCount(2, 'categories.0.items.0.images');

        $this->assertStringEndsWith('first.webp', $response->json('categories.0.items.0.images.0.url'), '排序小的在前，是封面');
        $this->assertStringEndsWith('second_thumb.webp', $response->json('categories.0.items.0.images.1.thumb_url'));
    }

    public function test_public_menu_does_not_leak_internal_fields(): void
    {
        $this->item('牛排', $this->category('主餐'));

        $item = $this->menu()->json('categories.0.items.0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'description', 'price', 'is_sold_out', 'images'], array_keys($item));
    }

    public function test_other_stores_menu_is_not_included(): void
    {
        $other = Store::factory()->create();
        MenuItem::create(['store_id' => $other->id, 'name' => '別店的菜', 'price' => 1]);
        $this->item('本店的菜');

        $names = collect($this->menu()->json('categories'))->flatMap(fn ($c) => collect($c['items'])->pluck('name'))->all();

        $this->assertSame(['本店的菜'], $names);
    }

    // ── 預點規則 ────────────────────────────────────────────────────────

    private function book(array $items)
    {
        return $this->postJson('/api/reservations', $this->bookingPayload(['items' => $items]));
    }

    public function test_a_sold_out_item_cannot_be_preordered(): void
    {
        $item = $this->item('提拉米蘇', null, ['is_sold_out' => true]);

        $this->book([['menu_item_id' => $item->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonPath('code', 'MENU_ITEM_SOLD_OUT');

        $this->assertSame(0, $this->slot->fresh()->booked, '預點有問題時不可佔名額');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_an_item_sold_out_after_the_customer_loaded_the_menu_is_rejected_by_the_server(): void
    {
        $item = $this->item('牛排');
        $this->menu()->assertOk(); // 顧客載入菜單時還有貨

        $item->update(['is_sold_out' => true]); // 之後被標示售完

        $this->book([['menu_item_id' => $item->id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'MENU_ITEM_SOLD_OUT');
    }

    public function test_an_item_in_an_inactive_category_cannot_be_preordered(): void
    {
        $item = $this->item('隱藏的菜', $this->category('停用的分類', active: false));

        $this->book([['menu_item_id' => $item->id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'INVALID_MENU_ITEM');
    }

    public function test_the_preordered_name_and_price_are_snapshotted(): void
    {
        $item = $this->item('牛排', null, ['price' => 680]);

        $code = $this->book([['menu_item_id' => $item->id, 'quantity' => 2]])->assertCreated()->json('code');

        // 之後門市改名、改價
        $item->update(['name' => '特選牛排', 'price' => 999]);

        $this->postJson('/api/reservations/lookup', ['code' => $code, 'phone' => '0912345678'])
            ->assertOk()
            ->assertJsonPath('items.0.name', '牛排')
            ->assertJsonPath('items.0.unit_price', 680);

        Sanctum::actingAs(User::factory()->staff($this->store)->create());
        $this->getJson('/api/admin/reservations')->assertOk()->assertJsonPath('data.0.items.0.name', '牛排');
    }

    public function test_a_renamed_item_in_old_reservations_survives_even_if_it_is_taken_off_the_menu(): void
    {
        $item = $this->item('牛排');
        $code = $this->book([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated()->json('code');

        $item->update(['is_active' => false]);

        $this->postJson('/api/reservations/lookup', ['code' => $code, 'phone' => '0912345678'])
            ->assertOk()->assertJsonPath('items.0.name', '牛排');
    }
}
