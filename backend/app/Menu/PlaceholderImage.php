<?php

namespace App\Menu;

/**
 * 產生示範用的圖片（抽象色塊），讓種子資料不必附真實照片。
 * 顏色由名稱決定，同一個名稱每次都產生同樣的圖；不同的 $variant 會有不同的構圖，用來示範多張圖。
 */
class PlaceholderImage
{
    /**
     * @return string 暫存檔的路徑（PNG），呼叫端用完要自己刪除
     */
    public static function create(string $seed, int $variant = 0, int $width = 1200, int $height = 800): string
    {
        $hue = (crc32($seed) % 360 + $variant * 37) % 360;

        $image = imagecreatetruecolor($width, $height);

        // 直向漸層
        [$r1, $g1, $b1] = self::hsl($hue, 0.55, 0.62);
        [$r2, $g2, $b2] = self::hsl(($hue + 40) % 360, 0.60, 0.38);
        for ($y = 0; $y < $height; $y++) {
            $t = $y / ($height - 1);
            $color = imagecolorallocate(
                $image,
                (int) ($r1 + ($r2 - $r1) * $t),
                (int) ($g1 + ($g2 - $g1) * $t),
                (int) ($b1 + ($b2 - $b1) * $t),
            );
            imageline($image, 0, $y, $width, $y, $color);
        }

        // 半透明圓形，位置由 seed 與 variant 決定
        imagealphablending($image, true);
        mt_srand(crc32($seed) + $variant);
        for ($i = 0; $i < 6; $i++) {
            $size = mt_rand((int) ($height * 0.2), (int) ($height * 0.7));
            [$r, $g, $b] = self::hsl(($hue + mt_rand(0, 120)) % 360, 0.6, 0.75);
            $overlay = imagecolorallocatealpha($image, $r, $g, $b, mt_rand(80, 110));
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), $size, $size, $overlay);
        }
        mt_srand();

        $path = tempnam(sys_get_temp_dir(), 'ph');
        imagepng($image, $path);

        return $path;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function hsl(int $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (intdiv($h, 60)) {
            0 => [$c, $x, 0],
            1 => [$x, $c, 0],
            2 => [0, $c, $x],
            3 => [0, $x, $c],
            4 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return [(int) (($r + $m) * 255), (int) (($g + $m) * 255), (int) (($b + $m) * 255)];
    }
}
