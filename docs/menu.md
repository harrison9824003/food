# 菜單：分類、說明、售完、多張圖片

供顧客訂位時「預點」餐點。**預點不計費**，只是讓門市提早備餐，訂金仍是「每人訂金 × 人數」。
訂位流程見 [booking.md](booking.md)，付款見 [payment.md](payment.md)。

## 資料

| 資料表 | 重點 |
|---|---|
| `menu_categories` | 門市自己的分類；`sort_order` 排序、`is_active` 停用；同一門市內名稱不可重複 |
| `menu_items` | 加上 `category_id`（可為空 = 未分類）、`description`、`sort_order`、`is_sold_out` |
| `menu_item_images` | 一個品項多張圖；`sort_order` 最小的是封面；只存相對路徑（`path`／`thumb_path`），不存完整網址 |
| `reservation_items` | 預點的快照：**品名**與**單價**都在下單當下複製，之後改名改價不影響已成立的訂位 |

三種「看不到／不能點」的狀態，意義不同：

| 狀態 | 顧客看得到 | 可預點 | 用途 |
|---|---|---|---|
| 正常 | ✅ | ✅ | |
| **今日售完**（`is_sold_out`） | ✅（標示「今日售完」） | ❌ | 臨時沒貨，明天還會有 |
| **下架**（`is_active = false`） | ❌ | ❌ | 這道菜不賣了 |
| **分類停用** | ❌（分類與底下所有品項） | ❌ | 例如整個「季節限定」分類暫停 |

- 品項**不能刪除**，只能下架：歷史訂位的預點內容會參照它。
- 刪除分類**不會**刪掉品項，底下的品項回到「未分類」（外鍵 `ON DELETE SET NULL`）。
- 預點時由**伺服器**檢查售完／下架／分類停用（`MENU_ITEM_SOLD_OUT`、`INVALID_MENU_ITEM`），不信任顧客手上那份可能過期的菜單。

## API

### 後台（`/api/admin/stores/{store}/…`，權限沿用門市設定：總部全部、店長自己門市、店員唯讀）

| 路由 | 說明 |
|---|---|
| `GET/POST /menu-categories`、`PATCH/DELETE /menu-categories/{id}` | 分類；列表帶 `items_count` |
| `GET/POST /menu-items`、`PATCH /menu-items/{id}` | 品項；`category_id` 必須屬於同一間門市 |
| `POST /menu-items/{id}/images` | 上傳圖片，`multipart/form-data`，欄位 `images[]`（可多張） |
| `PUT /menu-items/{id}/images/order` | `{ ids: [...] }` 重新排序，第一個是封面 |
| `DELETE /menu-items/{id}/images/{imageId}` | 刪除圖片（連同檔案） |

用 A 店的網址去存取 B 店的分類、品項、圖片會得到 `404`（scoped binding），不會改到別人的資料。

### 顧客端

`GET /api/stores/{id}/menu` → `{ categories: [{ id, name, items: [{ id, name, description, price, is_sold_out, images: [{ url, thumb_url }] }] }] }`

- 依分類的 `sort_order` 排序，分類內依品項的 `sort_order`。
- 沒有分類的品項歸在最後的「其他」（`id: null`）。
- 沒有任何可見品項的分類不會出現。
- 不含內部欄位（`is_active`、`sort_order`）。

## 圖片上傳的安全設計

使用者上傳的檔案是不可信的輸入。處理方式是**不保存上傳的檔案，而是把圖片解碼後重新編碼成我們自己產生的 WebP**
（`app/Menu/MenuImageProcessor.php`）。

| 威脅 | 對策 | 測試 |
|---|---|---|
| 副檔名／Content-Type 造假（PHP 檔改名 `.jpg`） | 不看副檔名，用檔案內容判斷：`mimes` 規則以 `finfo` 判斷，處理器再用 `getimagesize` 與真正解碼確認。解不開就不是圖片 | `rejects_a_non_image_file_disguised…` |
| SVG 夾帶腳本（XSS） | **不收 SVG**；只收 JPEG／PNG／WebP | `rejects_svg…` |
| 圖片尾端夾帶程式碼（polyglot） | 重新編碼，輸出只剩像素，夾帶的內容不會被帶到輸出檔 | `content_appended…_does_not_survive` |
| EXIF 洩漏拍攝位置、裝置資訊 | 重新編碼，所有中繼資料（EXIF、註解）都不會保留 | `metadata_embedded…_does_not_survive` |
| 解壓縮炸彈（幾百 bytes 的檔案宣告成 30000×30000 像素） | **解碼之前**用檔頭的尺寸檢查像素上限與記憶體需求，超過就友善地拒絕，而不是讓 PHP 記憶體耗盡 | `rejects_a_decompression_bomb…` |
| 檔案損毀／上傳中斷 | 解碼失敗就拒絕。JPEG 還要額外檢查有沒有以 EOI 結尾（見下） | `rejects_a_corrupt_image`、`rejects_truncated…` |
| 使用者檔名造成路徑穿越或覆蓋 | 檔名一律由伺服器產生（`menu/{store}/{item}/{uuid}.webp`），完全不使用使用者提供的檔名 | `…server_generated_names` |
| 一次塞爆（大量檔案、超大檔） | 每品項最多 8 張、單檔 5MB、單次請求最多 8 張；nginx `client_max_body_size` 與 PHP `post_max_size` 都調整成一致 | `at_most_eight_images`、`oversized_files…` |
| 兩人同時上傳，加起來超過張數上限 | 先鎖住品項再數張數 | （`MenuImageService::add`） |
| 一批裡有一張壞圖 | **全有或全無**：先全部處理完才寫入，任何一張失敗整批都不存 | `one_bad_file_rejects_the_whole_batch` |
| 寫檔後資料庫失敗，留下孤兒檔案 | 失敗時把已寫出的檔案清掉 | `files_written_before_a_database_failure…` |
| 權限 | 沿用 `StorePolicy`；跨門市存取回 404／403 | `upload_requires_authentication…` |

**截斷的 JPEG 是個值得記下的坑**：libjpeg 對壞掉的 JPEG 很「寬容」，檔案被截斷時會把能解的部分解出來、剩下填灰色，
而且**不報錯也不發任何警告**（實測過）。所以光靠 `imagecreatefromjpeg` 的回傳值擋不住上傳中斷的圖片，
處理器會沿著 JPEG 的標記結構走一遍，確認主圖有以 EOI 結尾。這個檢查能容忍部分相機／手機在 EOI 後面附加資料
（多圖格式、動態照片），也不會被 EXIF 內嵌縮圖自己的 EOI 騙過；碰到看不懂的結構時放行，交給解碼器決定，
寧可多收一張怪圖，也不要誤擋正常的手機照片。

其他處理：

- 縮成最長邊 1600px 的大圖與 400px 的縮圖（**不放大**較小的圖），統一輸出 WebP（品質 82）。
- 套用 EXIF 方向（手機直拍的照片不會躺著）、保留 PNG 的透明度。
- 實測：3000×2000 的 JPEG（122KB）處理後是 1600×1066 的 WebP（約 9KB）。這是色塊測試圖，壓縮率比真實照片好很多；
  真實的手機照片處理後的大小我沒有量過，會比這個大。

### 儲存與提供

- Laravel 的 `uploads` 磁碟（`config/filesystems.php`），程式只透過 `Storage` 抽象層存取；
  想換成 S3／MinIO，改 `MENU_IMAGE_DISK` 與磁碟設定即可，程式與資料表都不用動（資料庫只存相對路徑）。
- Docker 裡是 named volume `uploads_data`：php-fpm 可寫，nginx **唯讀**，以 `/uploads/` 直接提供，不經過 PHP。
  不用 `storage:link`，因為 symlink 在 Windows 的 bind mount 上不可靠。
- 一次性的 `uploads-init` 容器把 volume 的擁有者設成 php-fpm 的執行身分（uid 82），否則第一次啟動時目錄是 root 的，上傳會失敗。
- nginx 的 `/uploads/`：沒有任何 fastcgi 設定（檔案只會被當靜態檔案送出，不會被執行）、`X-Content-Type-Options: nosniff`、
  `Cache-Control: public, immutable`（檔名含隨機 UUID，內容永遠不會變）、不開目錄列表。
- 實測：`/uploads/`、`/uploads/menu/` 回 404；`/uploads/../index.php` 回 404。

## 前端

**後台**（門市設定 → 菜單）：左邊分類（可新增／編輯／刪除、顯示品項數），右邊品項表格（封面縮圖、售完與上架的快速開關）。
品項對話框可編輯所有欄位，並管理圖片：上傳（多選）、◀ ▶ 調整順序（第一張標示「封面」）、刪除、點圖預覽大圖。
新品項要先儲存才能上傳圖片（圖片需要品項存在），儲存後視窗不關，可以接著傳圖。

**前台**（訂位第 4 步「選餐」）：分類頁籤；每個品項是縮圖卡片（多張圖時角落顯示張數），點圖或品名開啟詳情——
多張大圖輪播＋完整說明＋數量；售完的品項灰階顯示並標示「今日售完」、不能點。
重新載入菜單時，已被下架或售完的品項會自動從購物車移除，避免送出時整筆被拒絕。

## 設定

| 環境變數 | 預設 | 說明 |
|---|---|---|
| `MENU_IMAGE_DISK` | `uploads` | 圖片儲存的磁碟 |
| `UPLOADS_ROOT` | `storage/app/uploads` | 本機磁碟的根目錄 |
| `UPLOADS_URL` | `/uploads` | 對外網址前綴 |

張數、大小、像素上限、縮圖尺寸、WebP 品質在 `config/menu.php`。PHP 的 `upload_max_filesize = 6M`、`post_max_size = 45M`
（8 張 × 5MB 再加 multipart 開銷）、nginx 的 `client_max_body_size 45M` 必須互相配合：nginx 的上限若小於 PHP，
超過時會直接回 413，Laravel 連驗證訊息都回不了。

## 示範資料

`php artisan db:seed` 會為兩間示範門市建立 4 個分類、9 個品項（含說明）、多張圖片（程式產生的抽象色塊，**不是真實照片**），
其中「提拉米蘇」標示今日售完。重複執行不會重複建立。

## 測試

```bash
docker compose exec php-fpm php artisan test --filter=Menu
```

> 提醒：測試裡的「惡意內容」一律用無害的標記字串（如 `CODE-MARKER`），不放真正的攻擊指令。
> 要證明的是「夾帶的內容不會留在輸出檔」，用什麼內容都一樣；而含有真正攻擊字串（例如網頁後門常見的指令）的原始碼檔案，
> 會被 Windows 的防毒軟體當成後門直接隔離——開發這個功能時就遇到過，測試檔案憑空消失。

## 沒有做的

規格選項（加料、辣度、份量）、營養標示、多語系、依日期或時段的菜單、庫存數量、拖曳排序（目前用 ◀ ▶ 按鈕）、
圖片裁切／旋轉編輯。
