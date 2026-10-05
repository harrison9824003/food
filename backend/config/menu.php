<?php

return [

    // 儲存圖片的磁碟（見 config/filesystems.php 的 uploads）。換成 s3 只要改這裡的設定，程式不用動。
    'disk' => env('MENU_IMAGE_DISK', 'uploads'),

    // 每個品項最多幾張圖
    'max_images' => 8,

    // 單檔上限（KB）
    'max_upload_kb' => 5120,

    // 解碼後的像素上限。小檔案也可能解壓縮成巨大的圖（decompression bomb），解碼前先擋掉，避免吃光記憶體。
    'max_pixels' => 40_000_000,

    // 大圖／縮圖的最長邊（像素）；原圖比較小就維持原尺寸，不放大
    'full_max_px' => 1600,
    'thumb_max_px' => 400,

    'webp_quality' => 82,

];
