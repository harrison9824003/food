<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import { EVENT_TYPE_LABELS } from '@/types/api'
import type { OutboxEventRow, OutboxList } from '@/types/api'

const status = ref<'failed' | 'pending' | 'sent'>('failed')
const events = ref<OutboxEventRow[]>([])
const counts = ref({ pending: 0, failed: 0, sent: 0 })
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(20)
const retrying = ref<number | null>(null)

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
  }
  try {
    const { data } = await http.get<OutboxList>('/admin/outbox-events', { params: { status: status.value, page: page.value } })
    events.value = data.events
    counts.value = data.counts
    total.value = data.meta.total
    perPage.value = data.meta.per_page
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入同步紀錄失敗'))
  } finally {
    loading.value = false
  }
}

async function retry(row: OutboxEventRow): Promise<void> {
  retrying.value = row.id
  try {
    await http.post(`/admin/outbox-events/${row.id}/retry`)
    ElMessage.success('已排入重送，約一分鐘內會送出')
    await load()
  } catch (error) {
    // 已經送成功的事件不可重送（會讓 ERP 收到第二次通知），後端會說明
    ElMessage.error(errorMessage(error, '重送失敗'))
    await load()
  } finally {
    retrying.value = null
  }
}

function describeNext(row: OutboxEventRow): string {
  if (row.status !== 'pending' || !row.next_attempt_at) {
    return '—'
  }
  const seconds = Math.round((Date.parse(row.next_attempt_at) - Date.now()) / 1000)
  if (row.attempts === 0 || seconds <= 0) {
    return '即將送出'
  }
  return seconds >= 60 ? `約 ${Math.round(seconds / 60)} 分鐘後重試` : `${seconds} 秒後重試`
}

function formatTime(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('zh-TW', { hour12: false }) : '—'
}

watch(status, () => {
  page.value = 1
  void load()
})

// 每 15 秒自動更新：排程每分鐘會處理一次，這樣不必手動重新整理就能看到「待送」變成「已送」
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  timer = setInterval(() => void load(true), 15000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <el-card>
    <template #header>ERP 同步</template>

    <el-alert
      v-if="counts.failed > 0"
      type="error"
      show-icon
      :closable="false"
      class="alert"
      :title="`有 ${counts.failed} 筆事件重試多次後仍無法送到 ERP，請確認 ERP 狀態後按「重送」`"
    />

    <p class="hint">
      訂位的建立與狀態變更會先記在資料庫，再由背景程式送到 ERP；送不出去時會依 1、2、4、8、16 分鐘自動重試，
      仍失敗才會進到「失敗」。同一張訂位的事件依序送出，前一筆沒成功時，後面的會等。
    </p>

    <el-radio-group v-model="status" class="tabs">
      <el-radio-button value="failed">失敗 <el-tag size="small" :type="counts.failed ? 'danger' : 'info'">{{ counts.failed }}</el-tag></el-radio-button>
      <el-radio-button value="pending">待送／重試中 <el-tag size="small" type="warning">{{ counts.pending }}</el-tag></el-radio-button>
      <el-radio-button value="sent">已送出 <el-tag size="small" type="success">{{ counts.sent }}</el-tag></el-radio-button>
    </el-radio-group>

    <el-table v-loading="loading" :data="events" :empty-text="status === 'failed' ? '沒有失敗的事件 🎉' : '沒有資料'">
      <el-table-column label="時間" width="170">
        <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="訂位代號" width="110">
        <template #default="{ row }">{{ row.reservation_code ?? `#${row.aggregate_id}` }}</template>
      </el-table-column>
      <el-table-column label="事件" width="110">
        <template #default="{ row }">{{ EVENT_TYPE_LABELS[row.event_type] ?? row.event_type }}</template>
      </el-table-column>
      <el-table-column label="嘗試" width="80">
        <template #default="{ row }">{{ row.attempts }} / {{ row.max_attempts }}</template>
      </el-table-column>
      <el-table-column v-if="status === 'pending'" label="下一步" width="140">
        <template #default="{ row }">{{ describeNext(row) }}</template>
      </el-table-column>
      <el-table-column v-if="status === 'sent'" label="送出時間" width="170">
        <template #default="{ row }">{{ formatTime(row.sent_at) }}</template>
      </el-table-column>
      <el-table-column label="最後的錯誤" min-width="220">
        <template #default="{ row }">
          <span v-if="row.last_error" class="error">{{ row.last_error }}</span>
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column v-if="status !== 'sent'" label="操作" width="90" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :loading="retrying === row.id" @click="retry(row)">
            {{ row.status === 'failed' ? '重送' : '立即重試' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-if="total > perPage"
      class="pager"
      layout="prev, pager, next"
      :total="total"
      :page-size="perPage"
      :current-page="page"
      @current-change="(p: number) => { page = p; void load() }"
    />
  </el-card>
</template>

<style scoped>
.alert {
  margin-bottom: 12px;
}
.hint {
  color: #909399;
  font-size: 13px;
  margin-top: 0;
}
.tabs {
  margin-bottom: 16px;
}
.error {
  color: #f56c6c;
  font-size: 13px;
}
.muted {
  color: #c0c4cc;
}
.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
