<?php

namespace App\Http\Requests\Admin;

class UploadMenuImagesRequest extends ManagesStoreSettingsRequest
{
    /**
     * 這裡只做第一道、便宜的檢查（大小、內容判斷出來的 MIME）。
     * 真正的把關是 MenuImageProcessor：把圖片解碼再重新編碼，解不開就拒絕。
     *
     * mimes 規則是用 finfo 讀檔案「內容」判斷，不看使用者提供的副檔名或 Content-Type。
     * 刻意不收 SVG：SVG 是 XML，可以夾帶 <script>，直接顯示在網頁上就是 XSS。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.config('menu.max_images')],
            'images.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('menu.max_upload_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.*.mimes' => '只能上傳 JPEG、PNG 或 WebP 圖片',
            'images.*.max' => '單張圖片不可超過 '.(config('menu.max_upload_kb') / 1024).'MB',
            'images.*.file' => '上傳失敗，請確認檔案沒有超過大小限制',
        ];
    }
}
