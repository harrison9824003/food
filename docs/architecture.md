# Docker 架構說明

## 服務一覽

| 容器 | 映像 | 對外 Port | 說明 |
|---|---|---|---|
| food_nginx | nginx:1.25-alpine | 80 | 統一入口，路由所有流量 |
| food_php_fpm | docker/php（自建 PHP 8.2） | — | Laravel API，FastCGI |
| food_mysql | mysql:8.0 | 3306 | 主資料庫（food_db + food_testing） |
| food_redis | redis:7-alpine | 6379 | Cache + Queue + Session |
| food_queue | 同 php-fpm | — | Laravel queue:work |
| food_scheduler | 同 php-fpm | — | schedule:run 每 60 秒 |
| food_frontend | node:20-alpine | 5173 | Vue 3 客戶前台 Vite dev server |
| food_admin | node:20-alpine | 5174 | Vue 3 管理後台 Vite dev server |
| food_mock_erp | node:20-alpine（自建） | 3000 | Express 模擬 POS/ERP |

### 使用者上傳的檔案

菜單圖片放在 named volume `uploads_data`：`php-fpm` 掛載為可寫（`storage/app/uploads`），`nginx` 掛載為**唯讀**（`/var/www/uploads`）並以
`/uploads/` 直接提供，不經過 PHP。一次性的 `uploads-init` 容器（`alpine`）負責把 volume 的擁有者設成 uid 82（`www-data`），
`php-fpm` 以 `depends_on: service_completed_successfully` 等它跑完，否則第一次啟動時目錄是 root 的、上傳會失敗。
詳見 [menu.md](menu.md)。上傳大小的限制要三處一致：`config/menu.php`、PHP 的 `post_max_size`（45M）、nginx 的 `client_max_body_size`（45M）。

### 壓測用的疊加設定

`docker-compose.loadtest.yml` 與 `docker-compose.yml` 疊加使用（`-f docker-compose.yml -f docker-compose.loadtest.yml`），
會覆蓋 `php-fpm` 的環境變數（開放策略標頭、關閉限流）並加入 `k6` 服務（`profiles: [k6]`，只有明確 `run` 時才啟動）。
不加 `-f` 時完全不生效，平常開發不會帶著這些設定。詳見 [loadtest.md](loadtest.md)。

`php-fpm` 的進程池在 `docker/php/fpm-pool.conf` 調高為 `pm.max_children = 40`（映像預設只有 5，同一時間最多處理 5 個請求，
壓測會變成把並發壓在 5）。

### 開發環境的 Vite 檔案監看

Windows 上 Docker 的 bind mount 不會傳遞檔案變更事件（inotify），Vite 收不到通知就會一直送快取裡的舊版程式碼。
`frontend` 與 `admin` 的 `vite.config.ts` 都設了 `server.watch.usePolling`，改檔案後不必重啟。

## nginx 路由規則

```
http://localhost/api/*       → FastCGI → php-fpm:9000 (Laravel)
http://localhost/admin/*     → Proxy  → admin:5174   (Vue admin)
http://localhost/mock-erp/*  → Proxy  → mock-erp:3000
http://localhost/*           → Proxy  → frontend:5173 (Vue 前台)

WebSocket HMR:
  /__vite_hmr   → frontend:5173
  /admin-ws     → admin:5174
```

## 網路與 Volume

所有容器共用 `food_net` bridge network，互相以 service name 為 hostname（e.g. `mysql`、`redis`、`mock-erp`）。

Named volumes（住在 WSL2 VM 內，避免 Windows bind-mount 效能問題）：
- `backend_vendor` — Laravel vendor/
- `frontend_node_modules` — frontend node_modules/
- `admin_node_modules` — admin node_modules/
- `mysql_data` — MySQL 資料持久化
- `redis_data` — Redis AOF 持久化

## 關鍵設計決策

### Named volumes for dependencies
Windows 上 bind-mount `vendor/` 和 `node_modules/` 會造成嚴重效能問題（Composer install 可慢 10x）。這些目錄改用 named volume 住在 WSL2 VM 內，source code 仍用 bind-mount 保有熱更新。

### Scheduler shell loop
```sh
while true; do php artisan schedule:run --verbose --no-interaction & sleep 60; done
```
不需要 cron daemon，日誌直接出現在 `docker logs food_scheduler`。

目前的排程（`backend/routes/console.php`）：

| 排程 | 時間 | 說明 |
|---|---|---|
| `slots:generate` | 每天 00:10（`Asia/Taipei`） | 為啟用中的門市補上新進入開放期間的可訂時段，冪等；見 [store-settings.md](store-settings.md) |
| `reservations:release-expired` | 每分鐘 | 釋放逾時未付款的暫時保留（到期 + 2 分鐘寬限），釋放前先向綠界對帳；見 [payment.md](payment.md) |
| `payments:reconcile` | 每 5 分鐘 | 向綠界查詢漏接背景通知的付款並補單，與背景通知走同一套冪等處理 |

三個排程都用 `withoutOverlapping()`（以 Redis 鎖避免上一輪還沒跑完又啟動下一輪）。

### 日誌

| channel | 檔案 | 內容 |
|---|---|---|
| 預設（`single`） | `storage/logs/laravel.log` | 一般應用日誌 |
| `payment` | `storage/logs/payment-YYYY-MM-DD.log` | 金流：通知原文、驗章失敗、冪等略過、晚到付款、對帳… |

所有日誌行都帶 `request_id`（`AssignRequestId` middleware 以 `Log::shareContext` 注入）。

### queue 和 scheduler 獨立容器
各自有不同重啟語意。重啟 php-fpm pool 不會中斷長時間執行的 queue job。

### FastCGI 而非 proxy_pass for /api
php-fpm 的原生協定是 FastCGI，比透過 HTTP proxy 的 overhead 更低。

### mock-erp 10% 隨機逾時
模擬外部系統失敗，用於測試 Laravel Outbox Pattern + 指數退避重試邏輯。

## 環境變數傳遞

- `docker-compose.yml` 讀 root `.env`（MYSQL_ROOT_PASSWORD 等 Docker 層級變數）
- `php-fpm`、`queue`、`scheduler` 透過 `env_file: ./backend/.env` 取得 Laravel 應用變數
- Laravel 連線用 service name：`DB_HOST=mysql`、`REDIS_HOST=redis`、`MOCK_ERP_URL=http://mock-erp:3000`
