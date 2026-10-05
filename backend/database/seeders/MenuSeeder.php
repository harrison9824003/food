<?php

namespace Database\Seeders;

use App\Menu\MenuImageService;
use App\Menu\PlaceholderImage;
use App\Models\MenuItem;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * 示範菜單：分類、品項、說明、多張圖片（程式產生的抽象色塊，不是真實照片）、一道「今日售完」。
 */
class MenuSeeder extends Seeder
{
    /** 分類 => [[品名, 說明, 價格, 圖片張數, 是否售完]] */
    private const MENU = [
        '前菜' => [
            ['凱薩沙拉', '新鮮蘿蔓生菜、帕瑪森起司與自製麵包丁', 180, 2, false],
            ['酥炸花枝圈', '外酥內嫩，附檸檬與塔塔醬', 220, 1, false],
        ],
        '主餐' => [
            ['炙燒牛排', '200g 美國牛肋，附當季時蔬與香煎馬鈴薯', 680, 3, false],
            ['松露野菇燉飯', '義大利米慢火燉煮，淋上黑松露醬', 420, 2, false],
            ['香草烤雞腿', '去骨雞腿排，迷迭香與蒜香醃漬', 360, 1, false],
        ],
        '甜點' => [
            ['提拉米蘇', '濃郁馬斯卡彭，手指餅乾浸咖啡酒', 150, 2, true],
            ['焦糖布丁', '', 120, 1, false],
        ],
        '飲品' => [
            ['錫蘭紅茶', '', 90, 0, false],
            ['氣泡水', '', 60, 0, false],
        ],
    ];

    public function run(MenuImageService $images): void
    {
        foreach (Store::whereIn('name', ['信義店', '台中店'])->get() as $store) {
            // 已經有菜單就不重灌，避免重複執行 db:seed 時一直累積圖片
            if ($store->menuItems()->exists()) {
                continue;
            }

            $order = 0;
            $categoryOrder = 0;

            foreach (self::MENU as $categoryName => $items) {
                $category = $store->menuCategories()->create(['name' => $categoryName, 'sort_order' => $categoryOrder++]);

                foreach ($items as [$name, $description, $price, $imageCount, $soldOut]) {
                    /** @var MenuItem $item */
                    $item = $store->menuItems()->create([
                        'category_id' => $category->id,
                        'name' => $name,
                        'description' => $description ?: null,
                        'price' => $price,
                        'sort_order' => $order++,
                        'is_sold_out' => $soldOut,
                    ]);

                    $paths = [];
                    for ($i = 0; $i < $imageCount; $i++) {
                        $paths[] = PlaceholderImage::create($name, $i);
                    }

                    if ($paths !== []) {
                        $images->add($item, $paths);
                    }

                    array_map('unlink', $paths);
                }
            }
        }
    }
}
