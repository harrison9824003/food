<?php

namespace Tests\Feature\Menu;

use App\Menu\MenuImageService;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MenuImageApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');

        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->item = MenuItem::create(['store_id' => $this->store->id, 'name' => '牛排', 'price' => 680]);
    }

    private function manager(): User
    {
        return tap(User::factory()->storeManager($this->store)->create(), fn ($u) => Sanctum::actingAs($u));
    }

    private function url(?MenuItem $item = null, ?Store $store = null, string $suffix = ''): string
    {
        $item ??= $this->item;
        $store ??= $this->store;

        return "/api/admin/stores/{$store->id}/menu-items/{$item->id}/images{$suffix}";
    }

    /** @param  list<UploadedFile>  $files */
    private function upload(array $files, ?MenuItem $item = null, ?Store $store = null)
    {
        // multipart 表單：postJson 會把檔案轉成 JSON 而丟掉，要用 post
        return $this->post($this->url($item, $store), ['images' => $files], ['Accept' => 'application/json']);
    }

    private function jpeg(string $name = 'photo.jpg', int $w = 800, int $h = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function storedFileCount(): int
    {
        return count(Storage::disk('uploads')->allFiles());
    }

    // ── 上傳 ────────────────────────────────────────────────────────────

    public function test_manager_uploads_images_which_are_stored_as_webp_with_server_generated_names(): void
    {
        $this->manager();

        $response = $this->upload([$this->jpeg('我的牛排照片 (final).jpg'), $this->jpeg('second.jpg', 1200, 900)])
            ->assertCreated()
            ->assertJsonCount(2, 'images');

        $images = MenuItemImage::orderBy('id')->get();
        $this->assertCount(2, $images);

        foreach ($images as $image) {
            Storage::disk('uploads')->assertExists([$image->path, $image->thumb_path]);
            $this->assertMatchesRegularExpression('#^menu/'.$this->store->id.'/'.$this->item->id.'/[0-9a-f-]{36}\.webp$#', $image->path);
            $this->assertStringNotContainsString('final', $image->path, '不可使用使用者提供的檔名');

            $info = getimagesizefromstring(Storage::disk('uploads')->get($image->path));
            $this->assertSame(IMAGETYPE_WEBP, $info[2], '存下來的一律是重新編碼過的 WebP');
        }

        $this->assertSame([1, 2], $images->pluck('sort_order')->all(), '依上傳順序排，最小的是封面');
        // URL 前綴由磁碟設定決定（Storage::fake 會換成 /storage；真實環境是 /uploads，見 docs/menu.md），這裡只確認路徑對得上
        $this->assertStringEndsWith($images[0]->path, $response->json('images.0.url'));
        $this->assertStringEndsWith($images[0]->thumb_path, $response->json('images.0.thumb_url'));
        $this->assertStringEndsWith('_thumb.webp', $response->json('images.0.thumb_url'));
    }

    public function test_new_uploads_are_appended_after_existing_images(): void
    {
        $this->manager();

        $this->upload([$this->jpeg()])->assertCreated();
        $this->upload([$this->jpeg()])->assertCreated();

        $this->assertSame([1, 2], MenuItemImage::orderBy('id')->pluck('sort_order')->all());
    }

    public function test_an_item_can_have_at_most_eight_images(): void
    {
        $this->manager();

        $this->upload(array_map(fn ($i) => $this->jpeg("a{$i}.jpg"), range(1, 5)))->assertCreated();
        $this->upload(array_map(fn ($i) => $this->jpeg("b{$i}.jpg"), range(1, 3)))->assertCreated();
        $this->assertSame(16, $this->storedFileCount()); // 8 張，每張一個大圖加一個縮圖

        $this->upload([$this->jpeg('ninth.jpg')])
            ->assertStatus(422)->assertJsonPath('code', 'TOO_MANY_IMAGES');

        $this->assertSame(8, MenuItemImage::count());
        $this->assertSame(16, $this->storedFileCount(), '被拒絕時不可留下任何檔案');
    }

    public function test_a_single_request_cannot_carry_more_than_eight_files(): void
    {
        $this->manager();

        $this->upload(array_map(fn ($i) => $this->jpeg("a{$i}.jpg"), range(1, 9)))
            ->assertStatus(422)->assertJsonValidationErrors('images', 'errors');
    }

    // ── 被拒絕的檔案 ────────────────────────────────────────────────────

    public function test_files_that_are_not_images_are_rejected_without_storing_anything(): void
    {
        $this->manager();

        $rejected = [
            'pdf' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>window.marker=1</script></svg>'),
            'gif' => UploadedFile::fake()->createWithContent('anim.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"),
            'text-as-jpg' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "marker"; ?>'),
        ];

        foreach ($rejected as $label => $file) {
            $this->upload([$file])->assertStatus(422);
            $this->assertSame(0, $this->storedFileCount(), "{$label} 不應留下檔案");
        }

        $this->assertSame(0, MenuItemImage::count());
    }

    public function test_oversized_files_are_rejected(): void
    {
        $this->manager();

        $this->upload([UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg')])
            ->assertStatus(422)->assertJsonValidationErrors('images.0', 'errors');
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_one_bad_file_rejects_the_whole_batch(): void
    {
        $this->manager();

        // 看起來是 JPEG（內容判斷也是 image/jpeg），但上傳到一半中斷了
        ob_start();
        imagejpeg(imagecreatetruecolor(500, 400), null, 90);
        $full = (string) ob_get_clean();
        $truncated = UploadedFile::fake()->createWithContent('cut.jpg', substr($full, 0, (int) (strlen($full) * 0.6)));

        $this->upload([$this->jpeg('good.jpg'), $truncated])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_IMAGE');

        $this->assertSame(0, MenuItemImage::count(), '整批全有或全無');
        $this->assertSame(0, $this->storedFileCount(), '好的那張也不能先存下來');
    }

    public function test_files_written_before_a_database_failure_are_cleaned_up(): void
    {
        MenuItemImage::creating(function () {
            throw new RuntimeException('db down');
        });

        try {
            $paths = [UploadedFile::fake()->image('a.jpg', 300, 200)->getRealPath()];
            app(MenuImageService::class)->add($this->item, $paths);
            $this->fail('應該拋出例外');
        } catch (RuntimeException) {
            // expected
        } finally {
            MenuItemImage::flushEventListeners();
        }

        $this->assertSame(0, $this->storedFileCount(), '不可留下沒有資料列對應的孤兒檔案');
    }

    // ── 權限 ────────────────────────────────────────────────────────────

    public function test_upload_requires_authentication_and_permission(): void
    {
        $this->upload([$this->jpeg()])->assertStatus(401);

        Sanctum::actingAs(User::factory()->staff($this->store)->create());
        $this->upload([$this->jpeg()])->assertStatus(403);

        Sanctum::actingAs(User::factory()->storeManager($this->other)->create());
        $this->upload([$this->jpeg()])->assertStatus(403);

        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_an_item_cannot_be_reached_through_another_stores_url(): void
    {
        $this->manager();
        $foreign = MenuItem::create(['store_id' => $this->other->id, 'name' => '別店的菜', 'price' => 1]);

        // 用自己門市的網址去傳圖給別間門市的品項：綁定限定在該門市底下 → 404
        $this->upload([$this->jpeg()], $foreign, $this->store)->assertStatus(404);
        $this->assertSame(0, MenuItemImage::count());
    }

    // ── 刪除與排序 ──────────────────────────────────────────────────────

    public function test_deleting_an_image_removes_its_files_and_row(): void
    {
        $this->manager();
        $this->upload([$this->jpeg('a.jpg'), $this->jpeg('b.jpg')])->assertCreated();
        [$first, $second] = MenuItemImage::orderBy('id')->get()->all();

        $this->deleteJson($this->url(suffix: "/{$first->id}"))
            ->assertOk()->assertJsonCount(1, 'images')->assertJsonPath('images.0.id', $second->id);

        Storage::disk('uploads')->assertMissing([$first->path, $first->thumb_path]);
        Storage::disk('uploads')->assertExists([$second->path, $second->thumb_path]);
        $this->assertDatabaseMissing('menu_item_images', ['id' => $first->id]);
    }

    public function test_an_image_of_another_item_cannot_be_deleted_through_this_one(): void
    {
        $this->manager();
        $otherItem = MenuItem::create(['store_id' => $this->store->id, 'name' => '湯', 'price' => 100]);
        $this->upload([$this->jpeg()], $otherItem)->assertCreated();
        $foreignImage = MenuItemImage::firstOrFail();

        $this->deleteJson($this->url(suffix: "/{$foreignImage->id}"))->assertStatus(404);

        $this->assertDatabaseHas('menu_item_images', ['id' => $foreignImage->id]);
        Storage::disk('uploads')->assertExists($foreignImage->path);
    }

    public function test_staff_cannot_delete_images(): void
    {
        $this->manager();
        $this->upload([$this->jpeg()])->assertCreated();
        $image = MenuItemImage::firstOrFail();

        Sanctum::actingAs(User::factory()->staff($this->store)->create());
        $this->deleteJson($this->url(suffix: "/{$image->id}"))->assertStatus(403);

        Storage::disk('uploads')->assertExists($image->path);
    }

    public function test_images_can_be_reordered_and_the_first_becomes_the_cover(): void
    {
        $this->manager();
        $this->upload([$this->jpeg('a.jpg'), $this->jpeg('b.jpg'), $this->jpeg('c.jpg')])->assertCreated();
        [$a, $b, $c] = MenuItemImage::orderBy('id')->pluck('id')->all();

        $this->putJson($this->url(suffix: '/order'), ['ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('images.0.id', $c)
            ->assertJsonPath('images.1.id', $a)
            ->assertJsonPath('images.2.id', $b);
    }

    public function test_reorder_must_list_exactly_the_items_images(): void
    {
        $this->manager();
        $this->upload([$this->jpeg('a.jpg'), $this->jpeg('b.jpg')])->assertCreated();
        [$a, $b] = MenuItemImage::orderBy('id')->pluck('id')->all();

        $otherItem = MenuItem::create(['store_id' => $this->store->id, 'name' => '湯', 'price' => 100]);
        $this->upload([$this->jpeg()], $otherItem)->assertCreated();
        $foreign = MenuItemImage::where('menu_item_id', $otherItem->id)->value('id');

        foreach ([[$a], [$a, $b, 99999], [$a, $foreign], [$a, $a]] as $ids) {
            $this->putJson($this->url(suffix: '/order'), ['ids' => $ids])->assertStatus(422);
        }

        $this->assertSame([$a, $b], MenuItem::find($this->item->id)->images()->pluck('id')->all(), '順序不可被動到');
    }
}
