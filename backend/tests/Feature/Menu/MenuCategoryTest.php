<?php

namespace Tests\Feature\Menu;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
    }

    private function manager(?Store $store = null): User
    {
        return tap(User::factory()->storeManager($store ?? $this->store)->create(), fn ($u) => Sanctum::actingAs($u));
    }

    private function url(string $path = '', ?Store $store = null): string
    {
        return '/api/admin/stores/'.($store ?? $this->store)->id."/menu-categories{$path}";
    }

    private function category(string $name, ?Store $store = null, int $order = 0, bool $active = true): MenuCategory
    {
        return MenuCategory::create(['store_id' => ($store ?? $this->store)->id, 'name' => $name, 'sort_order' => $order, 'is_active' => $active]);
    }

    public function test_manager_manages_categories_of_their_own_store(): void
    {
        $this->manager();

        $id = $this->postJson($this->url(), ['name' => '主餐', 'sort_order' => 2])
            ->assertCreated()->assertJsonPath('name', '主餐')->assertJsonPath('is_active', true)->assertJsonPath('items_count', 0)
            ->json('id');

        $this->patchJson($this->url("/{$id}"), ['name' => '招牌主餐', 'is_active' => false])
            ->assertOk()->assertJsonPath('name', '招牌主餐')->assertJsonPath('is_active', false);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', '招牌主餐');
    }

    public function test_categories_are_listed_in_sort_order_with_their_item_counts(): void
    {
        $dessert = $this->category('甜點', order: 2);
        $starter = $this->category('前菜', order: 1);
        MenuItem::create(['store_id' => $this->store->id, 'category_id' => $starter->id, 'name' => '沙拉', 'price' => 100]);
        MenuItem::create(['store_id' => $this->store->id, 'category_id' => $starter->id, 'name' => '濃湯', 'price' => 100]);
        $this->manager();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('0.name', '前菜')->assertJsonPath('0.items_count', 2)
            ->assertJsonPath('1.id', $dessert->id)->assertJsonPath('1.items_count', 0);
    }

    public function test_validation_and_per_store_uniqueness(): void
    {
        $this->manager();
        $this->category('主餐');
        $this->category('主餐', $this->other); // 別間門市同名沒關係

        $this->postJson($this->url(), [])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
        $this->postJson($this->url(), ['name' => '主餐'])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
        $this->postJson($this->url(), ['name' => str_repeat('長', 51)])->assertStatus(422)->assertJsonValidationErrors('name', 'errors');
        $this->postJson($this->url(), ['name' => '前菜'])->assertCreated();
    }

    public function test_renaming_to_its_own_name_is_allowed(): void
    {
        $category = $this->category('主餐');
        $this->manager();

        $this->patchJson($this->url("/{$category->id}"), ['name' => '主餐', 'sort_order' => 5])->assertOk();
    }

    public function test_deleting_a_category_keeps_its_items_as_uncategorized(): void
    {
        $category = $this->category('主餐');
        $item = MenuItem::create(['store_id' => $this->store->id, 'category_id' => $category->id, 'name' => '牛排', 'price' => 680]);
        $this->manager();

        $this->deleteJson($this->url("/{$category->id}"))->assertOk();

        $this->assertDatabaseMissing('menu_categories', ['id' => $category->id]);
        $this->assertNull($item->fresh()->category_id, '品項不能跟著消失，回到未分類');
    }

    public function test_staff_can_read_but_not_change_categories(): void
    {
        $category = $this->category('主餐');
        Sanctum::actingAs(User::factory()->staff($this->store)->create());

        $this->getJson($this->url())->assertOk()->assertJsonCount(1);
        $this->postJson($this->url(), ['name' => '甜點'])->assertStatus(403);
        $this->patchJson($this->url("/{$category->id}"), ['name' => 'x'])->assertStatus(403);
        $this->deleteJson($this->url("/{$category->id}"))->assertStatus(403);
        $this->assertDatabaseHas('menu_categories', ['id' => $category->id]);
    }

    public function test_categories_are_protected_across_stores(): void
    {
        $foreign = $this->category('別店的分類', $this->other);
        $this->manager();

        $this->getJson($this->url('', $this->other))->assertStatus(403);
        $this->postJson($this->url('', $this->other), ['name' => 'x'])->assertStatus(403);
        // 用自己門市的網址去改／刪別間的分類：綁定限定在該門市底下 → 404
        $this->patchJson($this->url("/{$foreign->id}"), ['name' => 'x'])->assertStatus(404);
        $this->deleteJson($this->url("/{$foreign->id}"))->assertStatus(404);
        $this->assertDatabaseHas('menu_categories', ['id' => $foreign->id, 'name' => '別店的分類']);
    }

    public function test_an_item_cannot_be_put_into_another_stores_category(): void
    {
        $foreign = $this->category('別店的分類', $this->other);
        $mine = $this->category('主餐');
        $item = MenuItem::create(['store_id' => $this->store->id, 'name' => '牛排', 'price' => 680]);
        $this->manager();
        $itemsUrl = '/api/admin/stores/'.$this->store->id.'/menu-items';

        $this->postJson($itemsUrl, ['name' => '湯', 'price' => 100, 'category_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id', 'errors');
        $this->patchJson("{$itemsUrl}/{$item->id}", ['category_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('category_id', 'errors');

        $this->patchJson("{$itemsUrl}/{$item->id}", ['category_id' => $mine->id])->assertOk()->assertJsonPath('category_id', $mine->id);
        $this->patchJson("{$itemsUrl}/{$item->id}", ['category_id' => null])->assertOk()->assertJsonPath('category_id', null);
    }
}
