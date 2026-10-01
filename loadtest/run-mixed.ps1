# 讀寫混合的吞吐量與延遲：比較 atomic 與 pessimistic（unsafe 只在超賣測試示範，這裡不測）。
#
#   .\loadtest\run-mixed.ps1
#   .\loadtest\run-mixed.ps1 -PeakVus 50 -Slots 20 -Tables 60
#
# 需要 Docker Desktop；k6 直接在 compose 網路內執行，不必另外安裝。

param(
    [int]$PeakVus = 50,
    [int]$Slots = 20,
    [int]$Tables = 60,
    [double]$ReadRatio = 0.8,
    [string[]]$Strategies = @('atomic', 'pessimistic')
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
    # 名額設得很大、每個時段都有足夠的桌位，讓這個測試量的是吞吐量，而不是客滿後的拒絕。
    # 桌位數用貼近真實門市的規模（幾十張）；幾百張桌子會讓每次查詢都在排序大量桌位，量到的是 PHP 而不是資料庫。
    $prepared = docker compose @compose exec -T php-fpm php artisan loadtest:prepare --capacity=5000 --slots=$Slots --tables=$Tables --json | ConvertFrom-Json
    Assert-Ok 'loadtest:prepare'
    $slotIds = $prepared.slot_ids -join ','

    Write-Host "==> [$strategy] k6：尖峰 $PeakVus 個並發，讀取比例 $ReadRatio" -ForegroundColor Cyan
    docker compose @compose run --rm k6 run `
        -e STRATEGY=$strategy -e STORE_ID=$($prepared.store_id) -e SLOT_IDS=$slotIds -e DATE=$($prepared.date) `
        -e PEAK_VUS=$PeakVus -e READ_RATIO=$ReadRatio `
        /scripts/mixed.js 2>&1 | ForEach-Object { "$_" } | Select-String -Pattern '^\['
    Assert-Ok 'k6'

    $k6 = Get-Content "loadtest/results/mixed-$strategy.json" -Raw | ConvertFrom-Json
    $rows += [pscustomobject]@{
        Strategy  = $strategy
        Requests  = $k6.requests
        RPS       = $k6.rps
        Created   = $k6.created
        Rejected  = $k6.rejected
        Errors    = $k6.other_errors
        ReadP95   = $k6.availability_ms.p95
        WriteAvg  = $k6.reservation_ms.avg
        WriteP95  = $k6.reservation_ms.p95
    }
}

Write-Host "`n==================== 結果 ====================" -ForegroundColor Green
$rows | Format-Table -AutoSize
$rows | ConvertTo-Json | Set-Content loadtest/results/mixed-summary.json -Encoding utf8
