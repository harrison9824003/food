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
