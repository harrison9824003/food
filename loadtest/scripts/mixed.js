// 讀寫混合的吞吐量與延遲測試：大多數人在看可訂時段，少數人真的送出訂位。
//
// 環境變數：
//   STRATEGY  atomic | pessimistic   （unsafe 只在 oversell.js 示範用）
//   STORE_ID  壓測門市 ID        SLOT_IDS  逗號分隔的時段 ID      DATE  時段日期（YYYY-MM-DD）
//   READ_RATIO 讀取比例（預設 0.8）   PEAK_VUS 尖峰並發（預設 50）   BASE_URL 預設 http://nginx

import http from 'k6/http'
import { Counter, Trend } from 'k6/metrics'

const BASE_URL = __ENV.BASE_URL || 'http://nginx'
const STRATEGY = __ENV.STRATEGY || 'atomic'
const STORE_ID = __ENV.STORE_ID
const SLOT_IDS = (__ENV.SLOT_IDS || '').split(',').filter(Boolean).map(Number)
const DATE = __ENV.DATE
const READ_RATIO = Number(__ENV.READ_RATIO || 0.8)
const PEAK_VUS = Number(__ENV.PEAK_VUS || 50)

const created = new Counter('reservations_created')
const rejected = new Counter('reservations_rejected')
const otherErrors = new Counter('other_errors')
const readLatency = new Trend('availability_latency', true)
const writeLatency = new Trend('reservation_latency', true)

export const options = {
  stages: [
    { duration: '10s', target: PEAK_VUS },
    { duration: '30s', target: PEAK_VUS },
    { duration: '5s', target: 0 },
  ],
}

export function setup() {
  if (!STORE_ID || SLOT_IDS.length === 0 || !DATE) {
    throw new Error('請指定 -e STORE_ID= -e SLOT_IDS=1,2,3 -e DATE=YYYY-MM-DD')
  }
}

export default function () {
  if (Math.random() < READ_RATIO) {
    const res = http.get(`${BASE_URL}/api/stores/${STORE_ID}/availability?date=${DATE}&party_size=2`, {
      headers: { Accept: 'application/json' },
    })
    readLatency.add(res.timings.duration)
    if (res.status !== 200) {
      otherErrors.add(1)
    }
    return
  }

  const slotId = SLOT_IDS[Math.floor(Math.random() * SLOT_IDS.length)]
  const phone = `09${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`
  const res = http.post(
    `${BASE_URL}/api/reservations`,
    JSON.stringify({ slot_id: slotId, party_size: 1 + Math.floor(Math.random() * 2), customer_name: 'k6', phone }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Booking-Strategy': STRATEGY,
      },
    },
  )
  writeLatency.add(res.timings.duration)

  if (res.status === 201) {
    created.add(1)
  } else if (res.status === 409) {
    rejected.add(1)
  } else {
    otherErrors.add(1)
  }
}

export function handleSummary(data) {
  const count = (name) => (data.metrics[name] ? data.metrics[name].values.count : 0)
  const trend = (name) => {
    const v = data.metrics[name] ? data.metrics[name].values : null
    return v ? { avg: Number(v.avg.toFixed(1)), p95: Number(v['p(95)'].toFixed(1)) } : null
  }
  const summary = {
    strategy: STRATEGY,
    peak_vus: PEAK_VUS,
    read_ratio: READ_RATIO,
    requests: count('http_reqs'),
    rps: Number(data.metrics.http_reqs.values.rate.toFixed(1)),
    created: count('reservations_created'),
    rejected: count('reservations_rejected'),
    other_errors: count('other_errors'),
    availability_ms: trend('availability_latency'),
    reservation_ms: trend('reservation_latency'),
  }

  return {
    [`/results/mixed-${STRATEGY}.json`]: JSON.stringify(summary, null, 2),
    stdout:
      `\n[${STRATEGY}] requests=${summary.requests} rps=${summary.rps} created=${summary.created} ` +
      `rejected=${summary.rejected} other=${summary.other_errors} ` +
      `read_p95=${summary.availability_ms?.p95}ms write_p95=${summary.reservation_ms?.p95}ms\n`,
  }
}
