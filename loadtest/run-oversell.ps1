# 依序用三種防超賣策略搶同一個時段，比較誰會超賣。
#
#   .\loadtest\run-oversell.ps1
#   .\loadtest\run-oversell.ps1 -Capacity 50 -Total 400 -Vus 100 -Party 2
#
# 需要 Docker Desktop；k6 直接在 compose 網路內執行，不必另外安裝。

param(
    [int]$Capacity = 50,
    [int]$Total = 400,
    [int]$Vus = 100,
    [int]$Party = 2,
    [string[]]$Strategies = @('unsafe', 'atomic', 'pessimistic')
)

# docker 會把進度訊息寫到 stderr，Windows PowerShell 5.1 會誤判成錯誤，所以不用 Stop，改成逐步檢查結束碼
$ErrorActionPreference = 'Continue'
function Assert-Ok([string]$step) { if ($LASTEXITCODE -ne 0) { throw "失敗：$step（結束碼 $LASTEXITCODE）" } }
Set-Location (Split-Path -Parent $PSScriptRoot)

$compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.loadtest.yml')
New-Item -ItemType Directory -Force -Path loadtest/results | Out-Null

Write-Host '==> 以壓測設定重建 php-fpm（開放策略標頭、關閉訂位限流）' -ForegroundColor Cyan
docker compose @compose up -d --force-recreate php-fpm 2>&1 | Out-Null
Assert-Ok '重建 php-fpm'
Start-Sleep -Seconds 5

$rows = @()
foreach ($strategy in $Strategies) {
    Write-Host "`n==> [$strategy] 重置壓測資料" -ForegroundColor Cyan
    $prepared = docker compose @compose exec -T php-fpm php artisan loadtest:prepare --capacity=$Capacity --slots=1 --json | ConvertFrom-Json
    $slotId = $prepared.slot_ids[0]

    Write-Host "==> [$strategy] k6：$Vus 個並發、共 $Total 筆請求搶 $Capacity 人名額的時段 #$slotId" -ForegroundColor Cyan
    docker compose @compose run --rm k6 run `
        -e STRATEGY=$strategy -e SLOT_ID=$slotId -e PARTY_SIZE=$Party -e TOTAL=$Total -e VUS=$Vus `
        /scripts/oversell.js

    $report = docker compose @compose exec -T php-fpm php artisan loadtest:report $slotId --json | ConvertFrom-Json
    $k6 = Get-Content "loadtest/results/oversell-$strategy.json" -Raw | ConvertFrom-Json
    $report | ConvertTo-Json | Set-Content "loadtest/results/report-$strategy.json" -Encoding utf8

    $rows += [pscustomobject]@{
        Strategy  = $strategy
        Created   = $k6.created
        Full      = $k6.rejected_slot_full
        NoTable   = $k6.rejected_no_table
        Errors    = $k6.other_errors
        Guests    = $report.guests
        Capacity  = $report.capacity
        Oversold  = $report.oversold_guests
        Drift     = $report.counter_drift
        RPS       = $k6.rps
        P95ms     = $k6.latency_ms.p95
    }
}

Write-Host "`n==================== 結果 ====================" -ForegroundColor Green
$rows | Format-Table -AutoSize
$rows | ConvertTo-Json | Set-Content loadtest/results/oversell-summary.json -Encoding utf8
Write-Host 'Oversold > 0 代表訂位人數超過名額（超賣）；Drift != 0 代表 slots.booked 計數被蓋掉（lost update）。'
