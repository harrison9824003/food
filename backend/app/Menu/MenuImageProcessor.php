<?php

namespace App\Menu;

use App\Exceptions\ApiException;
use GdImage;

/**
 * 把使用者上傳的圖片「重新編碼」成我們自己產生的 WebP（大圖＋縮圖）。
 *
 * 為什麼一定要重新編碼，而不是把上傳的檔案原封不動存起來：
 *  - 檔案內容可以和副檔名、Content-Type 完全不符（PHP 檔改名成 .jpg）。
 *    真的把像素解碼出來，解不開就不是圖片；解得開，重新編碼後輸出的也只剩像素。
 *  - 圖片的中繼資料（EXIF：GPS 位置、拍攝裝置、縮圖）、以及夾在檔案尾端或註解欄位裡的任何內容，
 *    都不會被帶到輸出檔。
 *  - 顧客手機拍的照片動輒 5–10MB，統一縮小後頁面載入快很多。
 */
class MenuImageProcessor
{
    private const SUPPORTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @return array{full: string, thumb: string, width: int, height: int} full／thumb 是 WebP 的二進位內容
     *
     * @throws ApiException 不是圖片、格式不支援、解析度過高、檔案損毀
     */
    public function process(string $path): array
    {
        $info = $this->inspect($path);
        $image = $this->decode($path, $info[2]);

        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $image = $this->applyExifOrientation($image, $path);
            }

            $full = $this->fit($image, (int) config('menu.full_max_px'));
            $thumb = $this->fit($image, (int) config('menu.thumb_max_px'));

            return [
                'full' => $this->encode($full),
                'thumb' => $this->encode($thumb),
                'width' => imagesx($full),
                'height' => imagesy($full),
            ];
        } finally {
            // GD 資源會在腳本結束時釋放，但 php-fpm 的 worker 會處理很多個請求，早點釋放才不會累積記憶體
            unset($image, $full, $thumb);
        }
    }

    /**
     * 在解碼之前先檢查，因為解碼才是吃記憶體的地方。
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function inspect(string $path): array
    {
        // getimagesize 只讀檔頭，不會解碼整張圖，所以可以先拿來擋掉明顯不對的檔案
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], self::SUPPORTED, true)) {
            throw $this->invalid('不是支援的圖片格式，請上傳 JPEG、PNG 或 WebP 檔案');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1) {
            throw $this->invalid('圖片檔案損毀');
        }

        // 解壓縮炸彈：一個幾 KB 的檔案可以宣告成 60000×60000 像素，解碼時把記憶體吃光。
        // 解碼後 GD 每個像素約 4 bytes，先估算需要多少記憶體，不夠就友善地拒絕，而不是讓 PHP 直接 fatal。
        if ($width * $height > (int) config('menu.max_pixels') || ! $this->hasMemoryFor($width, $height)) {
            throw $this->invalid('圖片解析度太高，請縮小後再上傳');
        }

        return $info;
    }

    private function decode(string $path, int $type): GdImage
    {
        // libjpeg 對壞掉的 JPEG 很「寬容」：檔案被截斷（例如上傳到一半連線中斷）時，它會盡量解出能解的部分、
        // 剩下的填灰色，而且不報錯也不發警告（實測過），結果是菜單上出現半張灰圖。所以要自己檢查檔案是否完整。
        if ($type === IMAGETYPE_JPEG && ! $this->jpegIsComplete($path)) {
            throw $this->invalid('圖片檔案不完整，可能是上傳中斷，請重新上傳');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };

        if ($image === false) {
            throw $this->invalid('圖片檔案損毀，無法讀取');
        }

        // WebP 不支援調色盤影像，轉成全彩再處理
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * 沿著 JPEG 的標記（marker）結構走一遍，確認主圖有以 EOI（FF D9）結尾。
     *
     * 不能只看檔案最後兩個位元組：有些相機／手機會在 EOI 後面附加資料（多圖格式 MPO、Samsung 的動態照片…），
     * 那樣的合法檔案結尾不是 FF D9。所以從頭走，找到「第一張圖」的 EOI 就算完整。
     * EXIF 裡的內嵌縮圖也有自己的 EOI，但它在 APP1 區段裡，依區段長度整段跳過，不會被誤認。
     *
     * 碰到看不懂的結構時回傳 true（放行），交給解碼器決定：寧可多收一張怪圖，也不要誤擋正常的手機照片。
     */
    private function jpegIsComplete(string $path): bool
    {
        $data = (string) file_get_contents($path);
        $length = strlen($data);

        if ($length < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return true;
        }

        $i = 2;
        while ($i < $length) {
            if ($data[$i] !== "\xFF") {
                return true; // 對不上標記，無法判斷
            }

            while ($i < $length && $data[$i] === "\xFF") {
                $i++; // 標記前允許有填充用的 FF
            }
            if ($i >= $length) {
                return false;
            }

            $marker = ord($data[$i++]);

            if ($marker === 0xD9) {
                return true; // EOI
            }

            // 沒有長度欄位的標記：TEM、RSTn、SOI
            if ($marker === 0x01 || $marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($i + 2 > $length) {
                return false;
            }

            $i += (ord($data[$i]) << 8) | ord($data[$i + 1]); // 區段長度（含長度欄位本身）

            // SOS 之後是壓縮的影像資料，一直到下一個「真正的」標記為止
            if ($marker === 0xDA) {
                while ($i < $length) {
                    $ff = strpos($data, "\xFF", $i);
                    if ($ff === false || $ff + 1 >= $length) {
                        return false; // 影像資料一直到檔案結束都沒有遇到下一個標記：被截斷了
                    }

                    $next = ord($data[$ff + 1]);

                    if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                        $i = $ff + 2; // FF00 是資料裡的 FF（byte stuffing），RSTn 是重新同步標記，都還是影像資料
                    } elseif ($next === 0xFF) {
                        $i = $ff + 1;
                    } else {
                        $i = $ff;

                        break;
                    }
                }
            }
        }

        return false;
    }

    /** 手機直拍的照片常是「橫的像素 + 方向旗標」，不處理的話顯示出來會躺著。 */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    /** 縮到最長邊不超過 $max。原圖比較小就維持原尺寸，不放大（放大只會變糊）。 */
    private function fit(GdImage $image, int $max): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $max) {
            return $image;
        }

        $scaled = $width >= $height
            ? imagescale($image, $max, -1, IMG_BICUBIC)
            : imagescale($image, (int) max(1, round($width * $max / $height)), $max, IMG_BICUBIC);

        return $scaled === false ? $image : $scaled;
    }

    private function encode(GdImage $image): string
    {
        // 保留 PNG 的透明度
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, (int) config('menu.webp_quality'));
        $data = (string) ob_get_clean();

        if (! $ok || $data === '') {
            throw $this->invalid('圖片處理失敗，請換一張圖片再試');
        }

        return $data;
    }

    private function hasMemoryFor(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return true;
        }

        // 解碼後一份、縮圖一份、再加上編碼緩衝，保守抓 ×2.5 倍的 4 bytes/像素
        $needed = (int) ($width * $height * 4 * 2.5);

        return $needed < $limit - memory_get_usage(true);
    }

    private function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException($message, 'INVALID_IMAGE', 422);
    }
}
