<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { RefundList, RefundRow } from '@/types/api'

type Tab = 'requested' | 'approved' | 'failed' | 'completed' | 'rejected'

const status = ref<Tab>('requested')
const refunds = ref<RefundRow[]>([])
const counts = ref<RefundList['counts']>({ requested: 0, approved: 0, failed: 0, completed: 0, rejected: 0 })
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(20)
const busy = ref<number | null>(null)

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
  }
  try {
    const { data } = await http.get<RefundList>('/admin/refunds', { params: { status: status.value, page: page.value } })
    refunds.value = data.refunds
    counts.value = data.counts
    total.value = data.meta.total
    perPage.value = data.meta.per_page
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入退款清單失敗'))
  } finally {
    loading.value = false
  }
}

/** 核准、駁回、重試共用：成功或失敗都重新載入，讓畫面和資料庫一致（別人可能已經處理過）。 */
async function act(row: RefundRow, action: 'approve' | 'reject' | 'retry', body?: object): Promise<void> {
  busy.value = row.id
  try {
    const { data } = await http.post<{ message: string }>(`/admin/refunds/${row.id}/${action}`, body)
    ElMessage.success(data.message)
  } catch (error) {
    ElMessage.error(errorMessage(error, '操作失敗'))
  } finally {
    busy.value = null
    await load()
  }
}

async function approve(row: RefundRow): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `將退還 $${row.amount} 給 ${row.customer_name}（訂位 ${row.reservation_code}）。核准後會立即呼叫金流商退款，無法撤回。`,
      '核准退款',
      { type: 'warning', confirmButtonText: '核准並退款', cancelButtonText: '返回' },
    )
  } catch {
    return
  }
  await act(row, 'approve')
}

async function reject(row: RefundRow): Promise<void> {
  let reason = ''
  try {
    const result = await ElMessageBox.prompt('駁回後訂金不退還，請寫下原因（會留存紀錄）', '駁回退款', {
      confirmButtonText: '駁回',
      cancelButtonText: '返回',
      inputPattern: /\S+/,
      inputErrorMessage: '請輸入駁回原因',
    })
    reason = result.value.trim()
  } catch {
    return
  }
  await act(row, 'reject', { reason })
}

async function retry(row: RefundRow): Promise<void> {
  try {
    await ElMessageBox.confirm(
      '重試前請先到綠界廠商後台確認這筆交易「沒有」已經退款，避免重複退款。',
      '重新退款',
      { type: 'warning', confirmButtonText: '已確認，重試', cancelButtonText: '返回' },
    )
  } catch {
    return
  }
  await act(row, 'retry')
}

function describeNext(row: RefundRow): string {
  if (row.status !== 'approved') {
    return '—'
  }
  if (!row.next_attempt_at) {
    return '處理中'
  }
  const seconds = Math.round((Date.parse(row.next_attempt_at) - Date.now()) / 1000)
  if (seconds <= 0) {
    return '即將重試'
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

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  timer = setInterval(() => void load(true), 15000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <el-card>
    <template #header>退款簽核</template>

    <el-alert
      v-if="counts.failed > 0"
      type="error"
      show-icon
      :closable="false"
      class="alert"
      :title="`有 ${counts.failed} 筆退款失敗，請看原因處理後按「重新退款」`"
    />

    <p class="hint">
      顧客取消（用餐前 24 小時以上）、門市取消、付款異常都會產生退款申請，由店長或總部核准後才會真正退錢。
      自己申請的退款不能自己核准。
    </p>

    <el-radio-group v-model="status" class="tabs">
      <el-radio-button value="requested">待簽核 <el-tag size="small" :type="counts.requested ? 'warning' : 'info'">{{ counts.requested }}</el-tag></el-radio-button>
      <el-radio-button value="approved">退款處理中 <el-tag size="small" type="warning">{{ counts.approved }}</el-tag></el-radio-button>
      <el-radio-button value="failed">失敗 <el-tag size="small" :type="counts.failed ? 'danger' : 'info'">{{ counts.failed }}</el-tag></el-radio-button>
      <el-radio-button value="completed">已退款 <el-tag size="small" type="success">{{ counts.completed }}</el-tag></el-radio-button>
      <el-radio-button value="rejected">已駁回 <el-tag size="small" type="info">{{ counts.rejected }}</el-tag></el-radio-button>
    </el-radio-group>

    <el-table v-loading="loading" :data="refunds" empty-text="沒有資料">
      <el-table-column label="申請時間" width="170">
        <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="訂位" min-width="140">
        <template #default="{ row }">
          <strong>{{ row.reservation_code }}</strong>
          <div class="sub">{{ row.customer_name }}・用餐 {{ row.dining_at }}</div>
        </template>
      </el-table-column>
      <el-table-column label="金額" width="90">
        <template #default="{ row }">${{ row.amount }}</template>
      </el-table-column>
      <el-table-column label="原因" min-width="150">
        <template #default="{ row }">
          {{ row.reason_label }}
          <div class="sub">申請人：{{ row.requested_by }}</div>
        </template>
      </el-table-column>
      <el-table-column v-if="status === 'approved'" label="下一步" width="140">
        <template #default="{ row }">{{ describeNext(row) }}</template>
      </el-table-column>
      <el-table-column v-if="status !== 'requested'" label="處理" min-width="170">
        <template #default="{ row }">
          {{ row.decided_by ?? '—' }}
          <div class="sub">{{ formatTime(row.decided_at) }}</div>
          <div v-if="row.reject_reason" class="sub">駁回原因：{{ row.reject_reason }}</div>
        </template>
      </el-table-column>
      <el-table-column v-if="status === 'approved' || status === 'failed'" label="嘗試／錯誤" min-width="220">
        <template #default="{ row }">
          第 {{ row.attempts }} 次
          <div v-if="row.failure_reason" class="error">{{ row.failure_reason }}</div>
        </template>
      </el-table-column>
      <el-table-column v-if="status === 'requested' || status === 'failed'" label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 'requested'">
            <el-tooltip :disabled="!row.requested_by_me" content="這是你自己申請的退款，需由其他人核准" placement="top">
              <span>
                <el-button link type="primary" :disabled="row.requested_by_me" :loading="busy === row.id" @click="approve(row)">核准</el-button>
              </span>
            </el-tooltip>
            <el-button link type="danger" :disabled="row.requested_by_me" :loading="busy === row.id" @click="reject(row)">駁回</el-button>
          </template>
          <el-button v-else link type="primary" :loading="busy === row.id" @click="retry(row)">重新退款</el-button>
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
.sub {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}
.error {
  color: #f56c6c;
  font-size: 12px;
  margin-top: 2px;
}
.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
