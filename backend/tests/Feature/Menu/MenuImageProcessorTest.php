<?php

namespace Tests\Feature\Menu;

use App\Exceptions\ApiException;
use App\Menu\MenuImageProcessor;
use Tests\TestCase;

/**
 * 圖片處理的安全性與正確性。測試的重點是「不信任上傳的檔案」。
 *
 * 這裡的「惡意內容」一律用無害的標記字串代替（例如 CODE-MARKER），不放真正的攻擊指令：
 * 要證明的是「夾帶的內容不會留在輸出檔」，用什麼內容都一樣；
 * 而且含有真正攻擊字串的原始碼檔案會被防毒軟體當成後門直接隔離，測試檔案就消失了。
 */
class MenuImageProcessorTest extends TestCase
{
    /** 夾帶在上傳檔案裡的標記；輸出檔絕對不應該出現它。 */
    private const MARKER = 'CODE-MARKER-9f3a';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function temp(string $contents, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img').$suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** 產生真正的圖片檔案內容（JPEG／PNG／WebP）。 */
    private function imageBytes(string $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 80, 40));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 20, 120, 220));

        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($image, null, 90),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
        };

        return (string) ob_get_clean();
    }

    /** @return array{width: int, height: int} */
    private function dimensionsOfWebp(string $binary): array
    {
        $info = getimagesizefromstring($binary);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2], '輸出必須是 WebP');

        return ['width' => $info[0], 'height' => $info[1]];
    }

    private function process(string $path): array
    {
        return app(MenuImageProcessor::class)->process($path);
    }

    private function assertRejected(string $path, string $needle = ''): void
    {
        try {
            $this->process($path);
            $this->fail('應該拒絕這個檔案');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_IMAGE', $e->errorCode);
            $this->assertSame(422, $e->status);
            if ($needle !== '') {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    // ── 接受的格式 ──────────────────────────────────────────────────────

    public function test_accepts_jpeg_png_and_webp_and_always_outputs_webp(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $type) {
            $result = $this->process($this->temp($this->imageBytes($type, 800, 600)));

            $this->assertSame(['width' => 800, 'height' => 600], $this->dimensionsOfWebp($result['full']), $type);
            $this->assertSame(800, $result['width']);
            $this->assertSame(600, $result['height']);
        }
    }

    public function test_large_images_are_scaled_down_and_get_a_smaller_thumbnail(): void
    {
        $result = $this->process($this->temp($this->imageBytes('jpeg', 3200, 2400)));

        $this->assertSame(['width' => 1600, 'height' => 1200], $this->dimensionsOfWebp($result['full']));
        $this->assertSame(['width' => 400, 'height' => 300], $this->dimensionsOfWebp($result['thumb']));
    }

    public function test_portrait_images_are_scaled_by_their_height(): void
    {
        $result = $this->process($this->temp($this->imageBytes('png', 1500, 3000)));

        $this->assertSame(['width' => 800, 'height' => 1600], $this->dimensionsOfWebp($result['full']));
        $this->assertSame(['width' => 200, 'height' => 400], $this->dimensionsOfWebp($result['thumb']));
    }

    public function test_small_images_are_never_enlarged(): void
    {
        $result = $this->process($this->temp($this->imageBytes('png', 120, 90)));

        $this->assertSame(['width' => 120, 'height' => 90], $this->dimensionsOfWebp($result['full']));
        $this->assertSame(['width' => 120, 'height' => 90], $this->dimensionsOfWebp($result['thumb']));
    }

    public function test_png_transparency_is_preserved(): void
    {
        $image = imagecreatetruecolor(50, 50);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $webp = $this->process($this->temp($png))['full'];

        $decoded = imagecreatefromstring($webp);
        $this->assertNotFalse($decoded);
        $alpha = (imagecolorat($decoded, 10, 10) >> 24) & 0x7F;
        $this->assertGreaterThan(100, $alpha, '全透明的像素轉成 WebP 後仍應是透明的');
    }

    // ── 拒絕的檔案 ──────────────────────────────────────────────────────

    public function test_rejects_a_non_image_file_disguised_with_an_image_extension(): void
    {
        // 內容是程式碼文字，副檔名卻是 .jpg：副檔名不可信，內容才是
        $this->assertRejected($this->temp('<?php echo "'.self::MARKER.'"; ?>', '.jpg'), '不是支援的圖片格式');
    }

    public function test_rejects_plain_text_and_empty_files(): void
    {
        $this->assertRejected($this->temp('just some text', '.png'));
        $this->assertRejected($this->temp('', '.png'));
    }

    public function test_rejects_svg_because_it_can_carry_scripts(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>window.'.self::MARKER.'=1</script></svg>';

        $this->assertRejected($this->temp($svg, '.svg'));
        $this->assertRejected($this->temp($svg, '.jpg'), '不是支援的圖片格式'); // 改副檔名也沒用
    }

    public function test_rejects_formats_we_do_not_support(): void
    {
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $gifBytes = (string) ob_get_clean();

        $this->assertRejected($this->temp($gifBytes, '.jpg'), '不是支援的圖片格式');
    }

    public function test_rejects_a_corrupt_image(): void
    {
        $valid = $this->imageBytes('jpeg', 400, 300);

        // 檔頭正常（getimagesize 看得懂），但後面的資料被砍掉一半：解碼時才會發現損毀
        $this->assertRejected($this->temp(substr($valid, 0, (int) (strlen($valid) / 2)), '.jpg'));
        // 檔頭正常、其餘全是亂碼
        $this->assertRejected($this->temp(substr($valid, 0, 40).str_repeat("\x00\xff", 500), '.jpg'));
        // 上傳中斷的各種位置：越接近結尾，GD 越看不出來（它不會報錯），所以需要自己檢查 EOI
        foreach ([0.5, 0.9, 0.99] as $ratio) {
            $this->assertRejected($this->temp(substr($valid, 0, (int) (strlen($valid) * $ratio)), '.jpg'), '不完整');
        }
    }

    public function test_rejects_truncated_png_and_webp(): void
    {
        foreach (['png', 'webp'] as $type) {
            $valid = $this->imageBytes($type, 400, 300);
            $this->assertRejected($this->temp(substr($valid, 0, (int) (strlen($valid) * 0.6)), ".{$type}"));
        }
    }

    public function test_accepts_a_jpeg_with_data_appended_after_its_end_marker(): void
    {
        // 有些相機／手機會在 EOI 後面附加資料（例如多圖格式、動態照片）；主圖是完整的，不該被誤擋
        $withTrailer = $this->imageBytes('jpeg', 300, 200).str_repeat('TRAILER', 40);

        $this->assertSame(['width' => 300, 'height' => 200], $this->dimensionsOfWebp($this->process($this->temp($withTrailer, '.jpg'))['full']));
    }

    public function test_an_embedded_exif_thumbnail_is_not_mistaken_for_the_end_of_the_image(): void
    {
        // EXIF 裡常帶一張內嵌的小縮圖，它有自己的 EOI。主圖若被截斷，不能因為看到縮圖的 EOI 就當成完整。
        $thumbnail = $this->imageBytes('jpeg', 40, 30);
        $main = $this->imageBytes('jpeg', 400, 300);
        $withThumb = $this->jpegWithExif($main, $this->tiffWithOrientation(1).$thumbnail);

        $this->assertRejected($this->temp(substr($withThumb, 0, (int) (strlen($withThumb) * 0.7)), '.jpg'), '不完整');
        $this->assertSame(400, $this->process($this->temp($withThumb, '.jpg'))['width'], '沒有被截斷的版本照常通過');
    }

    public function test_rejects_a_decompression_bomb_before_decoding_it(): void
    {
        // 一個幾百 bytes 的 PNG，檔頭卻宣告成 30000×30000 像素（9 億像素，解碼要幾 GB）
        $png = $this->imageBytes('png', 10, 10);
        $bomb = substr($png, 0, 16).pack('N', 30000).pack('N', 30000).substr($png, 24);

        $this->assertLessThan(2000, strlen($bomb));
        $this->assertRejected($this->temp($bomb, '.png'), '解析度太高');
    }

    // ── 重新編碼帶來的好處 ──────────────────────────────────────────────

    /** 在 JPEG 的 SOI 後面插入一個 APP1（EXIF）區段。 */
    private function jpegWithExif(string $jpeg, string $tiff): string
    {
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /** 最小的 TIFF/EXIF 結構：只有一個 Orientation 標籤。 */
    private function tiffWithOrientation(int $orientation): string
    {
        return "II*\x00".pack('V', 8)            // little-endian、第一個 IFD 在偏移 8
            .pack('v', 1)                          // 1 個標籤
            .pack('vvVv', 0x0112, 3, 1, $orientation)."\x00\x00" // Orientation, SHORT, count 1
            .pack('V', 0);                         // 沒有下一個 IFD
    }

    public function test_metadata_embedded_in_the_upload_does_not_survive(): void
    {
        $gps = 'GPS-MARKER-LAT-25.0330-LON-121.5654';
        $jpeg = $this->imageBytes('jpeg', 300, 200);

        // EXIF 區段裡藏著「位置」，另外再加一段 COM（註解）區段
        $withMetadata = $this->jpegWithExif($jpeg, $this->tiffWithOrientation(1).$gps);
        $comment = 'comment-'.self::MARKER;
        $withMetadata = substr($withMetadata, 0, 2)."\xFF\xFE".pack('n', 2 + strlen($comment)).$comment.substr($withMetadata, 2);

        $this->assertStringContainsString($gps, $withMetadata, '前提：上傳的檔案確實帶有這些內容');
        $this->assertStringContainsString($comment, $withMetadata);

        $result = $this->process($this->temp($withMetadata, '.jpg'));

        foreach (['full', 'thumb'] as $key) {
            $this->assertStringNotContainsString($gps, $result[$key]);
            $this->assertStringNotContainsString($comment, $result[$key]);
            $this->assertStringNotContainsString('Exif', $result[$key]);
        }
    }

    public function test_content_appended_to_a_valid_image_does_not_survive(): void
    {
        // 「圖片 + 尾端夾帶其他內容」（polyglot）：圖片本身是合法的，所以 getimagesize 等檢查都過得去
        $polyglot = $this->imageBytes('jpeg', 200, 200).'<?php echo "'.self::MARKER.'"; ?>';

        $result = $this->process($this->temp($polyglot, '.jpg'));

        foreach (['full', 'thumb'] as $key) {
            $this->assertStringNotContainsString(self::MARKER, $result[$key]);
            $this->assertStringNotContainsString('<?php', $result[$key]);
        }
    }

    public function test_exif_orientation_is_applied_so_photos_are_not_sideways(): void
    {
        // 手機直拍：像素是橫的 300×200，但 EXIF 說「順時針轉 90 度」才是正確方向
        $jpeg = $this->jpegWithExif($this->imageBytes('jpeg', 300, 200), $this->tiffWithOrientation(6));

        $result = $this->process($this->temp($jpeg, '.jpg'));

        $this->assertSame(['width' => 200, 'height' => 300], $this->dimensionsOfWebp($result['full']));
    }
}
