// 模擬 POS/ERP：接收 Laravel Outbox worker 送來的訂位事件。
//
// 刻意做成「不可靠」，用來展示 Outbox 的重試、冪等與失敗清單：
//   - 隨機失敗：事件還沒處理就回 408（請求逾時）
//   - 回應遺失：事件「已經處理」了，卻回 504 —— 最棘手的情況，Laravel 以為失敗而重送，
//     靠 Idempotency-Key 去重才不會在 ERP 產生重複資料
//   - 整個關閉：所有請求回 503，用來展示重試用盡後進入失敗清單
//   - 乱序偵測：同一張訂位的事件若序號比已收到的小，代表順序被打亂（會記錄下來）
//
// 資料都放在記憶體，容器重啟就清空。

const express = require('express');
const cors = require('cors');

const app = express();
app.use(express.json({ limit: '1mb' }));
app.use(cors());

/** 控制故障行為（POST /control 可即時調整） */
const control = {
  mode: 'normal', // normal | down | reject
  failureRate: 0.1, // normal 模式下，事件處理「之前」就失敗的機率（408）
  lostResponseRate: 0.05, // normal 模式下，事件已處理但回應遺失的機率（504）
};

/** Idempotency-Key → 當時的處理結果。同一個鍵再來一次，不重複處理。 */
const processed = new Map();
/** 已套用的事件，依收到順序 */
const applied = [];
/** 每張訂位目前收到的最大事件序號 */
const lastSequence = new Map();
const stats = { received: 0, applied: 0, duplicates: 0, simulatedFailures: 0, lostResponses: 0, outOfOrder: 0, rejected: 0 };

app.post('/events', (req, res) => {
  stats.received += 1;
  const key = req.get('Idempotency-Key');
  const event = req.body || {};

  if (!key) {
    return res.status(400).json({ error: 'Idempotency-Key header is required' });
  }

  // 整個關閉：模擬 ERP 當機或維護
  if (control.mode === 'down') {
    stats.simulatedFailures += 1;
    return res.status(503).json({ error: 'ERP is down (simulated)' });
  }

  // 資料有問題：模擬 ERP 永久拒絕（重試也沒用）
  if (control.mode === 'reject') {
    stats.rejected += 1;
    return res.status(422).json({ error: 'Event rejected (simulated)' });
  }

  if (!event.aggregate_id || !event.event_type || typeof event.sequence !== 'number') {
    stats.rejected += 1;
    return res.status(422).json({ error: 'aggregate_id, event_type and sequence are required' });
  }

  // 冪等：這個鍵已經處理過，直接回覆「成功」，但不再套用一次
  if (processed.has(key)) {
    stats.duplicates += 1;
    console.log(`[MOCK ERP] duplicate ignored: ${key}`);
    return res.status(200).json({ ...processed.get(key), duplicate: true });
  }

  // 處理「之前」就失敗
  if (Math.random() < control.failureRate) {
    stats.simulatedFailures += 1;
    console.log(`[MOCK ERP] simulated timeout for ${key}`);
    return res.status(408).json({ error: 'Request Timeout (simulated)' });
  }

  // 套用事件
  const previous = lastSequence.get(event.aggregate_id) ?? 0;
  const outOfOrder = event.sequence < previous;
  if (outOfOrder) {
    stats.outOfOrder += 1;
    console.warn(`[MOCK ERP] OUT OF ORDER: ${key} sequence ${event.sequence} < ${previous}`);
  }
  lastSequence.set(event.aggregate_id, Math.max(previous, event.sequence));

  const result = { message: 'Event applied', key, out_of_order: outOfOrder };
  processed.set(key, result);
  applied.push({ key, ...event, applied_at: new Date().toISOString(), out_of_order: outOfOrder });
  stats.applied += 1;
  console.log(`[MOCK ERP] applied ${key} (${event.event_type} #${event.aggregate_id})`);

  // 已處理，但回應遺失：Laravel 看到逾時會重送，這時要靠上面的冪等鍵擋掉
  if (Math.random() < control.lostResponseRate) {
    stats.lostResponses += 1;
    console.log(`[MOCK ERP] response lost for ${key} (event WAS applied)`);
    return res.status(504).json({ error: 'Gateway Timeout (simulated, but the event was applied)' });
  }

  return res.status(200).json(result);
});

// 目前的故障設定
app.get('/control', (req, res) => res.json(control));

// 調整故障設定：{ "mode": "down" } 或 { "failureRate": 0.5 }
app.post('/control', (req, res) => {
  const { mode, failureRate, lostResponseRate } = req.body || {};

  if (mode !== undefined) {
    if (!['normal', 'down', 'reject'].includes(mode)) {
      return res.status(422).json({ error: 'mode must be normal, down or reject' });
    }
    control.mode = mode;
  }
  for (const [name, value] of [['failureRate', failureRate], ['lostResponseRate', lostResponseRate]]) {
    if (value !== undefined) {
      if (typeof value !== 'number' || value < 0 || value > 1) {
        return res.status(422).json({ error: `${name} must be a number between 0 and 1` });
      }
      control[name] = value;
    }
  }

  console.log(`[MOCK ERP] control changed: ${JSON.stringify(control)}`);
  return res.json(control);
});

// 已套用的事件（最近 100 筆）
app.get('/events', (req, res) => res.json(applied.slice(-100).reverse()));

app.get('/stats', (req, res) => res.json({ ...stats, appliedTotal: applied.length, control }));

// 清空所有資料並恢復預設設定（demo 前重置用）
app.post('/reset', (req, res) => {
  processed.clear();
  applied.length = 0;
  lastSequence.clear();
  Object.keys(stats).forEach((k) => (stats[k] = 0));
  Object.assign(control, { mode: 'normal', failureRate: 0.1, lostResponseRate: 0.05 });
  return res.json({ message: 'reset' });
});

app.get('/health', (req, res) => res.json({ status: 'ok' }));

const PORT = process.env.PORT || 3000;
app.listen(PORT, '0.0.0.0', () => {
  console.log(`[MOCK ERP] Listening on port ${PORT}`);
});
