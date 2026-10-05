# 門市設定與時段產生

對應里程碑 3。門市、營業時間、公休日、桌位、時段名額全部存在資料庫，由後台維護；`StoreSeeder` 只灌示範資料。

## 設定項目

| 項目 | 資料表 | 說明 |
|---|---|---|
| 門市基本資料 | `stores` | 名稱、地址、電話、每人訂金、啟用狀態 |
| 開放規則 | `stores` | 提前開放天數 `advance_days`、訂位截止分鐘 `cutoff_minutes`、每時段預設名額 `slot_capacity_default` |
| 營業時間 | `store_business_hours` | 每週 7 天，每天開店／打烊時間與時段間隔（15／30／60 分鐘）；可設整天公休 |
| 公休日 | `store_closed_dates` | 特定日期公休（國定假日、員工旅遊…） |
| 桌位 | `dining_tables` | 桌號、人數上下限、可否併桌、是否啟用 |
| 時段名額 | `slots` | 某天某時段的 `capacity` 與 `booked` |
| 菜單 | `menu_categories`、`menu_items`、`menu_item_images` | 分類、說明、今日售完、多張圖片；供顧客訂位時預點（不計費）。品項只能下架、不能刪除。見 [menu.md](menu.md) |

`cutoff_minutes` 目前只是儲存設定，實際的「超過截止時間不可訂」在里程碑 4 的訂位流程中判斷。

## 權限

| 動作 | 總部管理員 | 店長 | 店員 |
|---|---|---|---|
| 檢視門市與所有設定 | 全部門市 | 自己門市 | 自己門市（唯讀） |
| 新增門市 | 可 | 不可 | 不可 |
| 修改門市名稱、每人訂金、啟用／停用 | 可 | **不可**（明確回 403，不默默忽略） | 不可 |
| 修改地址、電話、開放規則 | 可 | 自己門市 | 不可 |
| 營業時間、公休日、桌位、時段名額 | 可 | 自己門市 | 不可 |

實作於 `app/Policies/StorePolicy.php`：`view`（檢視）、`manageSettings`（寫入設定）、`updateCore`（名稱／訂金／停用）、`create`。

所有 `/stores/{store}/...` 巢狀路由都使用 **scoped binding**：`{diningTable}`、`{slot}`、`{closedDate}` 只會在該門市底下查詢。
用 A 店的網址去存取 B 店的資源會得到 404，而不是改到別人的資料；直接打 B 店網址則是 403。

## API

皆在 `/api/admin` 之下，需要 Bearer Token。

| 路由 | 說明 |
|---|---|
| `GET /stores` | 門市列表；總部看全部，其他角色只看自己門市（回傳陣列，不分頁） |
| `POST /stores` | 新增門市（總部） |
| `GET /stores/{store}`、`PATCH /stores/{store}` | 檢視／修改 |
| `GET /stores/{store}/business-hours` | 固定回 7 筆（週日=0…週六=6）；尚未設定的日子視為公休 |
| `PUT /stores/{store}/business-hours` | 一次覆寫 7 天，回 `{ hours, slots: { created, deleted } }` |
| `GET /stores/{store}/closed-dates` | 今天以後的公休日 |
| `POST /stores/{store}/closed-dates` | `{ closed_date, reason? }`；該日已有訂位回 `409 CLOSED_DATE_HAS_RESERVATIONS` |
| `DELETE /stores/{store}/closed-dates/{id}` | 取消公休，該日時段會補回 |
| `GET /stores/{store}/tables` | 桌位列表（含已停用） |
| `POST /stores/{store}/tables`、`PATCH /stores/{store}/tables/{id}` | 新增／修改；**不提供刪除**，以 `is_active=false` 停用（桌位會被歷史訂位參照） |
| `GET /stores/{store}/slots?date=YYYY-MM-DD` | 該日時段，含 `booked` 與 `remaining` |
| `PATCH /stores/{store}/slots/{id}` | 調整名額；低於已訂人數回 `409 CAPACITY_BELOW_BOOKED` |
| `POST /stores/{store}/slots/generate` | 手動觸發時段產生，回 `{ created, deleted }` |

驗證重點：時段間隔只允許 15／30／60；營業日必須有開店與打烊時間且打烊晚於開店；桌位人數下限不得大於上限
（更新時會與另一個欄位的現值比對）；桌號在同一門市內唯一（不同門市可重複）。

## 時段怎麼產生

`slots` 的每一列由營業時間展開而來，由 `App\Services\SlotGenerator::sync()` 負責。

**展開規則**：從今天到「今天 + `advance_days`」逐日檢查——該星期公休、或該日是公休日就跳過；否則從開店時間起每隔
`slot_minutes` 產生一個時段，直到「開始時間」早於打烊時間為止（例如 11:00–13:00、間隔 30 分鐘 → 11:00、11:30、12:00、12:30）。
名額取自 `slot_capacity_default`。

**`sync()` 是冪等的**，重複執行不會有副作用：

- 缺的時段用 `INSERT IGNORE` 補上，靠 `UNIQUE(store_id, slot_date, start_time)` 防重複。
- **已存在的時段一律不改**——不會蓋掉管理員手動調整的名額，更不會動到已訂位的 `booked`。
  （所以修改「每時段預設名額」只影響之後新產生的時段。）
- 不再屬於營業時間的舊時段，只有在「`booked = 0` 且沒有任何訂位列參照它」時才會刪除。
  只看 `booked = 0` 不夠：曾有人訂、後來取消的時段 `booked` 也是 0，但 `reservations` 仍以外鍵參照，硬刪會違反外鍵並丟失歷史。
  這個條件直接寫在 `DELETE` 的 `WHERE` 內，而不是先查再刪，避免查到刪除之間剛好有人訂走。
- 停用中的門市不產生時段。

**觸發時機**

| 時機 | 做法 |
|---|---|
| 每天 00:10 | Scheduler 執行 `php artisan slots:generate`，補上新進入開放期間的那一天 |
| 儲存營業時間 | 立即為該門市重新同步 |
| 調長 `advance_days` 或重新啟用門市 | 立即同步，不必等隔天排程 |
| 取消公休日 | 立即同步，時段補回來 |
| 手動 | 後台「依營業時間重新產生時段」／`POST .../slots/generate` |

### 公休日與訂位的衝突

設定公休日時，該日若已有人訂位（任一時段 `booked > 0`）就拒絕（409），請管理員先處理那些訂位。
檢查時會對該日所有時段列 `SELECT ... FOR UPDATE`：訂位端是以條件式 `UPDATE` 佔名額，同樣會鎖時段列，
因此「檢查有沒有人訂」到「寫入公休日」之間不會有新訂位插進來。

沒人訂的時段會直接移除。若有時段因為曾被取消的訂位參照而無法刪除，它會留在資料表中，
所以**里程碑 4 的可訂查詢必須同時排除公休日**，不能只看 `slots` 有沒有列。

### 調整時段名額

名額不可低於已訂人數。判斷放在 `UPDATE` 的 `WHERE booked <= :capacity`，以 affected rows 判斷成敗，
而不是「先讀 `booked` 再判斷再寫入」——後者在讀與寫之間有人訂位時，會讓 `capacity < booked`。
這和防超賣使用的條件式原子更新是同一種手法；資料庫的 `CHECK (booked <= capacity)` 則是最後一道防線。
名額設為 `0` 代表暫停該時段（不是刪除）。

## 測試

```bash
docker compose exec php-fpm php artisan test --filter=Store
docker compose exec php-fpm php artisan test --filter=SlotGeneration
```

測試用 `Carbon::setTestNow` 固定「今天」為 2026-10-05（週一），讓開放天數與星期的計算結果可預期。

## 示範資料

`php artisan db:seed` 會建立兩間示範門市：

| 門市 | 營業 | 開放天數 | 桌位 |
|---|---|---|---|
| 信義店 | 週二至週日 11:00–21:00（週一公休），每人訂金 $200 | 30 天 | A1、A2、B1、B2、C1 |
| 台中店 | 每天 11:00–21:00，免訂金 | 14 天 | A1、A2、B1、B2、C1 |

並直接展開未來的可訂時段。
