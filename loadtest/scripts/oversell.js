// 搶同一個時段的超賣測試。
//
// 大量並發請求同時搶同一個時段的名額（名額遠小於請求總人數），
// 跑完後用 `php artisan loadtest:report <slot_id>` 檢查有沒有超賣。
//
// 環境變數：
//   STRATEGY   atomic | pessimistic | unsafe   （用 X-Booking-Strategy 標頭指定，需 docker-compose.loadtest.yml）
//   SLOT_ID    要搶的時段 ID（loadtest:prepare 會印出）
//   PARTY_SIZE 每筆訂位人數（預設 2）
//   TOTAL      總請求數（預設 400）
//   VUS        並發使用者數（預設 100）
//   BASE_URL   預設 http://nginx（k6 與 nginx 同在 compose 網路內）

import http from 'k6/http'
import { Counter } from 'k6/metrics'

const BASE_URL = __ENV.BASE_URL || 'http://nginx'
const STRATEGY = __ENV.STRATEGY || 'atomic'
const SLOT_ID = Number(__ENV.SLOT_ID)
const PARTY_SIZE = Number(__ENV.PARTY_SIZE || 2)
const TOTAL = Number(__ENV.TOTAL || 400)
const VUS = Number(__ENV.VUS || 100)

const created = new Counter('reservations_created')
const rejectedFull = new Counter('rejected_slot_full')
const rejectedNoTable = new Counter('rejected_no_table')
const otherErrors = new Counter('other_errors')

export const options = {
  scenarios: {
    rush: {
      // 所有 VU 共同分攤固定數量的請求，並且同時開跑，模擬開放訂位瞬間的搶位
      executor: 'shared-iterations',
      vus: VUS,
      iterations: TOTAL,
      maxDuration: '3m',
    },
  },
  // 409 是預期的結果（客滿），不算失敗
  thresholds: {},
}

export function setup() {
  if (!SLOT_ID) {
    throw new Error('請用 -e SLOT_ID=<時段 ID> 指定要搶的時段')
  }
}

export default function () {
  const phone = `09${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`

  const res = http.post(
    `${BASE_URL}/api/reservations`,
    JSON.stringify({ slot_id: SLOT_ID, party_size: PARTY_SIZE, customer_name: 'k6', phone }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Booking-Strategy': STRATEGY,
      },
    },
  )

  if (res.status === 201) {
    created.add(1)
    return
  }

  let code = ''
  try {
    code = res.json('code')
  } catch (e) {
    // 非 JSON 回應（例如 502），歸入其他錯誤
  }

  if (res.status === 409 && code === 'SLOT_FULL') {
    rejectedFull.add(1)
  } else if (res.status === 409 && code === 'NO_TABLE_AVAILABLE') {
    rejectedNoTable.add(1)
  } else {
    otherErrors.add(1)
    if (__ITER < 3) {
      console.warn(`unexpected response: ${res.status} ${res.body}`)
    }
  }
}

export function handleSummary(data) {
  const count = (name) => (data.metrics[name] ? data.metrics[name].values.count : 0)
  const dur = data.metrics.http_req_duration.values
  const summary = {
    strategy: STRATEGY,
    slot_id: SLOT_ID,
    party_size: PARTY_SIZE,
    vus: VUS,
    requests: count('http_reqs'),
    created: count('reservations_created'),
    rejected_slot_full: count('rejected_slot_full'),
    rejected_no_table: count('rejected_no_table'),
    other_errors: count('other_errors'),
    rps: Number(data.metrics.http_reqs.values.rate.toFixed(1)),
    latency_ms: {
      avg: Number(dur.avg.toFixed(1)),
      p50: Number(dur.med.toFixed(1)),
      p95: Number(dur['p(95)'].toFixed(1)),
      max: Number(dur.max.toFixed(1)),
    },
  }

  return {
    [`/results/oversell-${STRATEGY}.json`]: JSON.stringify(summary, null, 2),
    stdout:
      `\n[${STRATEGY}] requests=${summary.requests} created=${summary.created} ` +
      `full=${summary.rejected_slot_full} no_table=${summary.rejected_no_table} other=${summary.other_errors} ` +
      `rps=${summary.rps} p95=${summary.latency_ms.p95}ms\n`,
  }
}
