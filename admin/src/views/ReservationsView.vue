<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { PAYMENT_STATUS_LABELS, REFUND_REASON_LABELS, STATUS_ACTION_LABELS, STATUS_OPTIONS } from '@/types/api'
import type { AdminReservation, Paginated, Store } from '@/types/api'

const auth = useAuthStore()

const rows = ref<AdminReservation[]>([])
const stores = ref<Store[]>([])
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(20)

const filters = reactive({ store_id: null as number | null, date: '', status: '', q: '', needs_refund: false })

async function load(): Promise<void> {
  loading.value = true
  try {
    // 空值不送；後端依登入者角色限縮範圍（非總部無法指定其他門市）
    const params: Record<string, string | number> = { page: page.value }
    if (filters.store_id) params.store_id = filters.store_id
    if (filters.date) params.date = filters.date
    if (filters.status) params.status = filters.status
    if (filters.q.trim()) params.q = filters.q.trim()
    if (filters.needs_refund) params.needs_refund = 1

    const { data } = await http.get<Paginated<AdminReservation>>('/admin/reservations', { params })
    rows.value = data.data
    total.value = data.meta.total
    perPage.value = data.meta.per_page
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入訂單失敗'))
  } finally {
    loading.value = false
  }
}

function search(): void {
  page.value = 1
  void load()
}

function reset(): void {
  Object.assign(filters, { store_id: null, date: '', status: '', q: '', needs_refund: false })
  search()
}

function onPageChange(next: number): void {
  page.value = next
  void load()
}

async function changeStatus(row: AdminReservation, status: 'arrived' | 'no_show' | 'cancelled'): Promise<void> {
  const label = STATUS_ACTION_LABELS[status]
  try {
    await ElMessageBox.confirm(
      `確定將「${row.customer_name}」${row.date} ${row.time} 的訂位標記為「${label}」？` +
        (status === 'arrived' ? '' : '\n座位與桌位會釋出給其他人，此動作無法復原。'),
      '變更訂位狀態',
      { type: status === 'arrived' ? 'info' : 'warning', confirmButtonText: '確定', cancelButtonText: '返回' },
    )
  } catch {
    return
  }

  try {
    const { data } = await http.patch<AdminReservation>(`/admin/reservations/${row.id}/status`, { status })
    Object.assign(row, data)
    ElMessage.success('已更新')
  } catch (error) {
    // 例如已付訂金需走退款流程、狀態已被別人改過，後端會說明原因
    ElMessage.error(errorMessage(error, '更新失敗'))
    void load()
  }
}

function actionLabel(action: keyof typeof STATUS_ACTION_LABELS): string {
  return STATUS_ACTION_LABELS[action]
}

function tagType(status: string): 'success' | 'warning' | 'info' | 'danger' {
  switch (status) {
    case 'confirmed':
    case 'arrived':
      return 'success'
    case 'pending_payment':
      return 'warning'
    case 'no_show':
      return 'danger'
    default:
      return 'info'
  }
}

onMounted(async () => {
  if (auth.isHq) {
    try {
      const { data } = await http.get<Store[]>('/admin/stores')
      stores.value = data
    } catch (error) {
      ElMessage.error(errorMessage(error, '載入門市失敗'))
    }
  }
  await load()
})
</script>

<template>
  <el-card>
    <template #header>訂單</template>

    <el-form inline class="filters" @submit.prevent="search">
      <el-form-item v-if="auth.isHq">
        <el-select v-model="filters.store_id" placeholder="全部門市" clearable style="width: 150px">
          <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-date-picker v-model="filters.date" type="date" value-format="YYYY-MM-DD" placeholder="用餐日期" style="width: 150px" />
      </el-form-item>
      <el-form-item>
        <el-select v-model="filters.status" placeholder="全部狀態" clearable style="width: 130px">
          <el-option v-for="o in STATUS_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-input v-model="filters.q" placeholder="姓名／手機／代號" clearable style="width: 180px" />
      </el-form-item>
      <el-form-item>
        <el-checkbox v-model="filters.needs_refund">只看待退款</el-checkbox>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit">搜尋</el-button>
        <el-button @click="reset">重設</el-button>
      </el-form-item>
    </el-form>

    <el-table v-loading="loading" :data="rows" empty-text="沒有符合的訂單" stripe>
      <el-table-column label="用餐時間" width="150">
        <template #default="{ row }">{{ row.date }} {{ row.time }}</template>
      </el-table-column>
      <el-table-column v-if="auth.isHq" label="門市" width="100">
        <template #default="{ row }">{{ row.store.name }}</template>
      </el-table-column>
      <el-table-column prop="customer_name" label="姓名" min-width="90" />
      <el-table-column prop="phone" label="手機" width="120" />
      <el-table-column label="人數" width="70">
        <template #default="{ row }">{{ row.party_size }}</template>
      </el-table-column>
      <el-table-column label="桌位" width="100">
        <template #default="{ row }">{{ row.tables.join('、') || '—' }}</template>
      </el-table-column>
      <el-table-column prop="code" label="代號" width="110" />
      <el-table-column label="狀態" width="90">
        <template #default="{ row }">
          <el-tag :type="tagType(row.status)">{{ row.status_label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="付款" min-width="130">
        <template #default="{ row }">
          <span v-if="row.deposit_amount > 0">${{ row.deposit_amount }} {{ PAYMENT_STATUS_LABELS[row.payment_status] ?? row.payment_status }}</span>
          <span v-else class="muted">免訂金</span>
          <!-- 錢已收到但訂位無法成立：等退款流程處理，先在這裡標出來避免被忽略 -->
          <el-tooltip v-if="row.needs_refund" placement="top">
            <template #content>
              <div v-for="p in row.payments.filter((x: { needs_refund: boolean }) => x.needs_refund)" :key="p.merchant_trade_no">
                {{ REFUND_REASON_LABELS[p.refund_reason] ?? p.refund_reason }}（${{ p.amount }}）
              </div>
            </template>
            <el-tag type="danger" size="small" class="refund-tag">待退款</el-tag>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column label="預點" min-width="140">
        <template #default="{ row }">
          <span v-if="!row.items?.length" class="muted">—</span>
          <div v-for="item in row.items" :key="item.name">{{ item.name }} ×{{ item.quantity }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="170" fixed="right">
        <template #default="{ row }">
          <!-- 可用的動作由後端的狀態轉換表決定，前端不重複實作規則 -->
          <el-button
            v-for="action in row.allowed_statuses"
            :key="action"
            link
            :type="action === 'arrived' ? 'success' : action === 'cancelled' ? 'danger' : 'warning'"
            @click="changeStatus(row, action)"
          >
            {{ actionLabel(action) }}
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
      @current-change="onPageChange"
    />
  </el-card>
</template>

<style scoped>
.filters {
  margin-bottom: 8px;
}
.muted {
  color: #c0c4cc;
}
.refund-tag {
  margin-left: 6px;
}
.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
