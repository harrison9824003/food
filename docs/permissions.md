# 帳號、認證與權限

## 角色

| 角色 | 代碼 | 隸屬門市 |
|---|---|---|
| 總部管理員 | `hq_admin` | 無（`store_id` 為 NULL） |
| 店長 | `store_manager` | 必須指定 |
| 店員 | `staff` | 必須指定 |

## 認證

Sanctum **Bearer Token**：登入後取得 token，之後放在 `Authorization: Bearer <token>`。

- token 8 小時過期（`SANCTUM_EXPIRATION`，單位分鐘）。
- 登入限流：同一 email + IP 每分鐘 5 次，超過回 `429 TOO_MANY_REQUESTS`。
- 帳號不存在與密碼錯誤回相同訊息與相近的回應時間，避免探測有效帳號。
- 停用帳號：無法登入；已發出的 token 立即失效（停用當下刪除全部 token，`active` middleware 也會再擋一次）。
- 登出只撤銷目前這支 token，不影響同帳號其他裝置。

| 路由 | 說明 |
|---|---|
| `POST /api/admin/login` | `{ email, password }` → `{ token, token_type, expires_at, user }` |
| `POST /api/admin/logout` | 撤銷目前 token |
| `GET /api/admin/me` | 目前使用者（含所屬門市） |

## 帳號管理權限矩陣

實作於 `app/Policies/UserPolicy.php`，逐格對應 `tests/Feature/Admin/UserManagementTest.php`。

| 動作 | 總部管理員 | 店長 | 店員 |
|---|---|---|---|
| 檢視帳號列表 | 全部（可用 `?store_id=` 篩選） | 僅自己門市 | 403 |
| 建立帳號 | 任何角色、任何門市 | 只能建「店員」且限自己門市 | 403 |
| 編輯帳號 | 任何帳號 | 只能編輯自己門市的店員 | 403 |
| 變更角色／門市 | 可 | 不可（升級或調店都會 403） | 403 |
| 停用帳號 | 可，但**不能停用自己** | 自己門市的店員 | 403 |
| 變更自己的角色 | 不可（防鎖死） | 不可 | 不可 |

設計重點：

- **不使用 `Gate::before` 讓總部無條件放行**，每條規則都明確寫在 Policy，方便測試與說明。
- **更新時同時檢查「修改前」與「修改後」的狀態**：只查修改後，店長能把別店店員調到自己店；只查修改前，店長能把店員升成店長。
- **自我鎖死防護**：任何人不能停用自己或改自己的角色，避免系統沒有人能管理。
- 角色與門市的組合（總部不可有門市、其他角色必須有門市）在 Form Request 驗證，更新時看「修改後的最終狀態」。

| 路由 | 說明 |
|---|---|
| `GET /api/admin/users` | 分頁列表（每頁 20） |
| `POST /api/admin/users` | 建立 |
| `PATCH /api/admin/users/{id}` | 編輯／停用（不提供刪除，只停用，保留稽核軌跡） |

## 統一錯誤格式

```json
{ "message": "沒有執行此操作的權限", "code": "FORBIDDEN", "errors": {} }
```

| HTTP | code | 情境 |
|---|---|---|
| 401 | `UNAUTHENTICATED` | 未登入、token 無效或過期 |
| 401 | `INVALID_CREDENTIALS` | 帳號或密碼錯誤 |
| 403 | `FORBIDDEN` | 已登入但無權限 |
| 403 | `ACCOUNT_DISABLED` | 帳號已停用 |
| 404 | `NOT_FOUND` | 找不到資源 |
| 422 | `VALIDATION_FAILED` | 驗證失敗，`errors` 為欄位錯誤 |
| 429 | `TOO_MANY_REQUESTS` | 被限流 |
| 500 | `SERVER_ERROR` | 未預期錯誤（正式環境不外洩內部訊息） |

## request_id

每個請求由 `AssignRequestId` 指派 UUID：寫入所有日誌（`Log::shareContext`），並放進回應 header `X-Request-Id`。上游帶來的值只有在格式是合法 UUID 時才沿用，避免日誌注入。使用者回報問題時，憑這個 ID 即可串起整條日誌。

## 示範帳號（僅本機／Demo，`php artisan db:seed`）

| Email | 角色 | 門市 | 密碼 |
|---|---|---|---|
| `hq@example.com` | 總部管理員 | — | `password` |
| `manager@example.com` | 店長 | 信義店 | `password` |
| `staff@example.com` | 店員 | 信義店 | `password` |

## 測試環境注意事項

Docker 會把 `backend/.env` 注入成環境變數，而 Laravel 讀 env 時 `$_SERVER` 優先，
所以 `phpunit.xml` 對 `DB_DATABASE`、`CACHE_STORE`、`SESSION_DRIVER`、`QUEUE_CONNECTION`、`MAIL_MAILER`
同時用 `force` 覆蓋 `$_ENV` 與 `$_SERVER`。少了這層，`RefreshDatabase` 會清空開發資料庫，限流計數也會落到開發用 Redis。
`ApiFoundationTest` 內有兩個防呆測試確保這個設定不會失效。

```bash
docker compose exec php-fpm php artisan test
```
