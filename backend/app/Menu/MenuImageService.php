<?php

namespace App\Menu;

use App\Exceptions\ApiException;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MenuImageService
{
    public function __construct(private readonly MenuImageProcessor $processor) {}

    /**
     * 為品項新增圖片。一次的所有檔案要嘛全部成功、要嘛全部不存：
     * 先全部處理完（任何一張有問題就整批拒絕，此時還沒寫入任何東西），再一起寫入。
     *
     * @param  list<string>  $paths  本機暫存檔路徑
     * @return list<MenuItemImage>
     *
     * @throws ApiException
     */
    public function add(MenuItem $item, array $paths): array
    {
        return DB::transaction(function () use ($item, $paths) {
            // 鎖住品項再數張數：兩個人同時上傳時，不鎖的話兩邊都數到「還有空位」，加起來就超過上限
            $locked = MenuItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $existing = $locked->images()->count();
            $max = (int) config('menu.max_images');

            if ($existing + count($paths) > $max) {
                throw new ApiException(
                    "每個品項最多 {$max} 張圖片（目前已有 {$existing} 張）",
                    'TOO_MANY_IMAGES',
                    422,
                );
            }

            $processed = array_map(fn (string $path) => $this->processor->process($path), $paths);

            $nextOrder = ((int) $locked->images()->max('sort_order')) + 1;
            $written = [];
            $images = [];

            try {
                foreach ($processed as $i => $result) {
                    // 檔名一律由伺服器產生，絕不使用使用者提供的檔名（防路徑穿越、覆蓋他人的檔案、猜測網址）
                    $base = "menu/{$locked->store_id}/{$locked->id}/".Str::uuid();

                    $this->disk()->put("{$base}.webp", $result['full']);
                    $written[] = "{$base}.webp";
                    $this->disk()->put("{$base}_thumb.webp", $result['thumb']);
                    $written[] = "{$base}_thumb.webp";

                    $images[] = $locked->images()->create([
                        'path' => "{$base}.webp",
                        'thumb_path' => "{$base}_thumb.webp",
                        'width' => $result['width'],
                        'height' => $result['height'],
                        'sort_order' => $nextOrder + $i,
                    ]);
                }
            } catch (Throwable $e) {
                // 寫到一半失敗：把已經寫出去的檔案清掉，不留下沒有資料列對應的孤兒檔案
                $this->disk()->delete($written);
                throw $e;
            }

            return $images;
        });
    }

    public function delete(MenuItemImage $image): void
    {
        $paths = [$image->path, $image->thumb_path];

        DB::transaction(function () use ($image) {
            $image->delete();
        });

        // 資料列確定刪掉之後才刪檔案：反過來的話，檔案刪了但資料庫交易失敗，就會留下指向不存在檔案的資料列
        try {
            $this->disk()->delete($paths);
        } catch (Throwable $e) {
            Log::warning('menu image files could not be deleted', ['paths' => $paths, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 重新排序。必須剛好包含該品項的所有圖片，不多不少：
     * 否則前端用過期的清單送出，就會把剛上傳的圖片排到不確定的位置，或動到別的品項的圖片。
     *
     * @param  list<int>  $orderedIds
     *
     * @throws ApiException
     */
    public function reorder(MenuItem $item, array $orderedIds): void
    {
        DB::transaction(function () use ($item, $orderedIds) {
            $locked = MenuItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $current = $locked->images()->pluck('id')->all();

            $expected = $current;
            sort($expected);
            $given = $orderedIds;
            sort($given);

            if ($expected !== $given) {
                throw new ApiException('圖片清單已變動，請重新整理後再排序', 'INVALID_IMAGE_ORDER', 422);
            }

            foreach ($orderedIds as $position => $id) {
                MenuItemImage::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('menu.disk'));
    }
}
