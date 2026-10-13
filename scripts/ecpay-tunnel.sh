#!/usr/bin/env bash
# 讓綠界測試環境的「背景通知」打得到本機。
#
#   ./scripts/ecpay-tunnel.sh up       開通道、把公開網址寫進 backend/.env、重建容器、做連通檢查
#   ./scripts/ecpay-tunnel.sh status   目前的通道與實際生效的設定
#   ./scripts/ecpay-tunnel.sh logs     即時追蹤金流日誌（看綠界通知進來）
#   ./scripts/ecpay-tunnel.sh down     關通道、還原 backend/.env、重建容器
#
# 只有 ECPAY_NOTIFY_URL 需要公開網址：背景通知是綠界的伺服器打過來的；
# 瀏覽器導回與前台網址是你自己的瀏覽器在連，維持 http://localhost 即可，CORS、Sanctum、Vite 都不用改。
# 設計說明見 docs/payment.md。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/backend/.env"
NOTIFY_PATH="/api/payments/ecpay/notify"
BEGIN_MARK="# >>> ecpay-tunnel（由 scripts/ecpay-tunnel.sh 產生，down 會移除，請勿手動修改）"
END_MARK="# <<< ecpay-tunnel"
# 讀 ECPAY_NOTIFY_URL 的服務：php-fpm 產生結帳表單；queue／scheduler 一併重建，避免各容器設定不一致
APP_SERVICES=(php-fpm queue scheduler)

compose() { docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" --profile tunnel "$@"; }
info()    { printf '\033[36m▸\033[0m %s\n' "$*"; }
ok()      { printf '\033[32m✔\033[0m %s\n' "$*"; }
warn()    { printf '\033[33m!\033[0m %s\n' "$*" >&2; }
die()     { printf '\033[31m✘\033[0m %s\n' "$*" >&2; exit 1; }

# 從通道容器的日誌找出 Quick Tunnel 分配到的網址（每次啟動都不同）
tunnel_url() {
  compose logs --no-log-prefix tunnel 2>/dev/null \
    | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1 || true
}

# 輸出「移除本腳本區塊後」的 .env 內容（不修改檔案）
without_block() {
  awk -v b="$BEGIN_MARK" -v e="$END_MARK" '
    $0 == b { skip = 1; next }
    $0 == e { skip = 0; next }
    !skip
  ' "$ENV_FILE"
}

# 移除 .env 中由本腳本寫入的區塊。用覆寫內容而非 mv 換檔，保留原檔權限
strip_block() {
  local tmp
  tmp="$(mktemp)"
  without_block > "$tmp"
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
}

# env_file 是在「建立」容器時注入成環境變數的，而且優先於 Laravel 自己讀的 .env，
# 所以改完 .env 只 restart 沒有用，一定要重建
recreate_app() {
  info "重建 ${APP_SERVICES[*]} 讓設定生效…"
  compose up -d --force-recreate --no-deps "${APP_SERVICES[@]}" >/dev/null
}

# 經公開網址 GET notify 路由，印出 HTTP 狀態碼。
# 用 GET 而不是 POST：預期得到 405（路由只收 POST），證明外部連得到我們，又不會在金流日誌留下一筆假的驗章失敗。
# 新網址剛建立的前幾秒 DNS 還查不到，而 macOS 會把「查不到」快取一段時間，之後重試也一直失敗；
# 所以改用 Cloudflare 的 DNS over HTTPS 查詢，繞過系統快取（curl 不支援時退回系統 DNS）
probe() {
  local doh=()
  curl --help all 2>/dev/null | grep -q -- '--doh-url' && doh=(--doh-url https://1.1.1.1/dns-query)
  curl -s -o /dev/null -w '%{http_code}' --max-time 5 ${doh[@]+"${doh[@]}"} "$1$NOTIFY_PATH" || true
}

effective_notify_url() {
  compose exec -T php-fpm php artisan tinker --execute="echo config('ecpay.notify_url');" 2>/dev/null | tail -n 1
}

cmd_up() {
  [[ -f "$ENV_FILE" ]] || die "找不到 $ENV_FILE"
  # 區塊外若已有生效的設定，兩邊會打架（誰覆蓋誰取決於工具），直接請使用者處理
  if without_block | grep -qE '^[[:space:]]*ECPAY_NOTIFY_URL='; then
    die "backend/.env 已經手動設定了 ECPAY_NOTIFY_URL，請先移除或註解掉再執行"
  fi

  [[ -n "$(compose ps -q --status running nginx 2>/dev/null)" ]] || die "nginx 沒有在執行，請先 docker compose up -d"

  info "啟動 Cloudflare Quick Tunnel…"
  # 一律重建：舊容器的日誌裡會留著上一次的網址，重建才能確定抓到的是這次的。
  # --no-deps：只動通道本身，不連帶重建使用者正在跑的 nginx 等服務
  compose up -d --force-recreate --no-deps --quiet-pull tunnel >/dev/null

  local url="" i
  for i in $(seq 1 30); do
    url="$(tunnel_url)"
    [[ -n "$url" ]] && break
    sleep 1
  done
  [[ -n "$url" ]] || die "30 秒內沒有取得公開網址，請看 docker compose --profile tunnel logs tunnel"
  ok "公開網址：$url"

  strip_block
  # 確保區塊前有換行（原檔最後一行可能沒有換行）
  [[ -z "$(tail -c 1 "$ENV_FILE")" ]] || printf '\n' >> "$ENV_FILE"
  {
    printf '%s\n' "$BEGIN_MARK"
    printf 'ECPAY_NOTIFY_URL=%s%s\n' "$url" "$NOTIFY_PATH"
    printf '%s\n' "$END_MARK"
  } >> "$ENV_FILE"
  ok "已寫入 backend/.env：ECPAY_NOTIFY_URL=$url$NOTIFY_PATH"

  recreate_app

  # 通道剛註冊好時 Cloudflare 那端可能還沒就緒，所以重試
  info "連通檢查（經公開網址打 notify 路由）…"
  local code=""
  for i in $(seq 1 30); do
    code="$(probe "$url")"
    [[ "$code" == "405" ]] && break
    sleep 2
  done
  if [[ "$code" == "405" ]]; then
    ok "外部可以連到 notify 路由（GET 回 405，符合預期：路由只收 POST）"
  else
    warn "連通檢查沒有通過（最後一次 HTTP 狀態：${code:-無回應}）。稍後執行 status 再看一次，或看 docker compose --profile tunnel logs tunnel"
  fi

  local effective
  effective="$(effective_notify_url)"
  if [[ "$effective" == "$url$NOTIFY_PATH" ]]; then
    ok "Laravel 實際生效的 notify 網址：$effective"
  else
    warn "Laravel 實際生效的 notify 網址是「$effective」，和預期不同（是否有 config:cache？）"
  fi

  cat <<EOF

接下來：
  1. 照常用 http://localhost 開前台，訂一個需要訂金的時段，按「前往付款」
  2. 綠界測試信用卡：4311-9522-2222-2222，有效期任一未來月份，安全碼 222，3D 驗證碼 1234（以綠界官方文件為準）
  3. 另開一個終端機執行 ./scripts/ecpay-tunnel.sh logs，可看到綠界的背景通知進來
  4. 測完執行 ./scripts/ecpay-tunnel.sh down

注意：通道開著時，任何人都能經由上面的網址連到你的本機服務。
EOF
}

cmd_down() {
  info "關閉通道…"
  compose stop tunnel >/dev/null 2>&1 || true
  compose rm -f tunnel >/dev/null 2>&1 || true
  if grep -qF "$BEGIN_MARK" "$ENV_FILE"; then
    strip_block
    ok "已從 backend/.env 移除 ECPAY_NOTIFY_URL"
    recreate_app
  else
    info "backend/.env 沒有本腳本寫入的設定，不需要還原"
  fi
  ok "Laravel 實際生效的 notify 網址：$(effective_notify_url)"
}

cmd_status() {
  local url
  if [[ -n "$(compose ps -q --status running tunnel 2>/dev/null)" ]]; then
    url="$(tunnel_url)"
    ok "通道執行中：${url:-（尚未取得網址）}"
    if [[ -n "$url" ]]; then
      local code
      code="$(probe "$url")"
      if [[ "$code" == "405" ]]; then ok "連通檢查通過（GET 回 405）"; else warn "連通檢查未通過（HTTP ${code:-無回應}）"; fi
    fi
  else
    info "通道沒有在執行"
  fi
  info "Laravel 實際生效的 notify 網址：$(effective_notify_url)"
}

cmd_logs() {
  info "追蹤 storage/logs/payment-*.log（Ctrl+C 結束）"
  compose exec php-fpm sh -c 'touch "storage/logs/payment-$(date +%F).log" && tail -n 20 -F storage/logs/payment-*.log'
}

case "${1:-}" in
  up)     cmd_up ;;
  down)   cmd_down ;;
  status) cmd_status ;;
  logs)   cmd_logs ;;
  *)
    sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
