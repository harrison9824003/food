# 快速啟動指南

## 前置需求

- Docker Desktop（Windows WSL2 模式）
- Git

## 第一次啟動

```powershell
# 1. 複製 .env
cp .env.example .env

# 2. 建置 Docker 映像
docker compose build

# 3. 啟動所有服務
docker compose up -d

# 4. 等待 MySQL healthy（約 30 秒）
docker compose ps

# 5. 生成 Laravel app key（若 backend/.env 的 APP_KEY 已填則略過）
docker compose exec php-fpm php artisan key:generate

# 6. 執行 migration
docker compose exec php-fpm php artisan migrate

# 7. 發布 Sanctum 設定（首次）
docker compose exec php-fpm php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

## 驗證各服務

| 項目 | 指令 | 預期結果 |
|---|---|---|
| 所有容器 | `docker compose ps` | 8 個 running/healthy |
| MySQL 連線 | `docker compose exec php-fpm php artisan migrate:status` | 無錯誤 |
| Redis 連線 | `docker compose exec php-fpm php artisan tinker --execute="echo Cache::put('t',1,60);"` | `1` |
| Queue worker | `docker compose logs queue` | 無 Exception |
| Scheduler | `docker compose logs scheduler` | 無 Exception |
| Laravel API | `curl http://localhost/api/` | HTTP 200 |
| 客戶前台 | 瀏覽器 `http://localhost/` | Vue 頁面 |
| 管理後台 | 瀏覽器 `http://localhost/admin/` | Vue 頁面 |
| Mock ERP | `curl http://localhost/mock-erp/health` | `{"status":"ok"}` |
| HMR | 修改 `.vue` 檔 | 瀏覽器自動更新 |

## 常用指令

```powershell
# 查看所有 log
docker compose logs -f

# 只看 queue worker log
docker compose logs -f queue

# 進入 PHP container（執行 artisan 等）
docker compose exec php-fpm bash

# 執行 artisan tinker
docker compose exec php-fpm php artisan tinker

# 停止所有服務
docker compose down

# 停止並清除 volumes（資料會消失！）
docker compose down -v
```

## 開發連線資訊

| 服務 | Host | Port | 帳號 / 密碼 |
|---|---|---|---|
| MySQL | localhost | 3306 | food / foodsecret |
| Redis | localhost | 6379 | 密碼：redissecret |
| Mock ERP API | localhost | 3000 | — |

## Mock ERP API

| 端點 | 說明 |
|---|---|
| `POST /reservations` | 接收訂位同步（10% 隨機逾時） |
| `GET /reservations` | 查看所有已同步訂位 |
| `GET /reservations/:id` | 查看特定訂位 |
| `POST /reservations/:id/retry` | 手動觸發重試（Demo 用） |
| `GET /failed` | 查看模擬失敗紀錄 |
| `GET /health` | 健康檢查 |
