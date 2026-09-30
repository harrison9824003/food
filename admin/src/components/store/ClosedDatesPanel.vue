<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { ClosedDate } from '@/types/api'
import { todayString } from '@/utils/date'

const props = defineProps<{ storeId: number; canManage: boolean }>()

const dates = ref<ClosedDate[]>([])
const loading = ref(false)
const adding = ref(false)
const newDate = ref('')
const newReason = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<ClosedDate[]>(`/admin/stores/${props.storeId}/closed-dates`)
    dates.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入公休日失敗'))
  } finally {
    loading.value = false
  }
}

function isPast(date: Date): boolean {
  const today = new Date(`${todayString()}T00:00:00`)
  return date.getTime() < today.getTime()
}

async function add(): Promise<void> {
  if (!newDate.value) {
    ElMessage.warning('請選擇日期')
    return
  }

  adding.value = true
  try {
    await http.post(`/admin/stores/${props.storeId}/closed-dates`, {
      closed_date: newDate.value,
      reason: newReason.value || null,
    })
    ElMessage.success('已設定公休')
    newDate.value = ''
    newReason.value = ''
    await load()
  } catch (error) {
    // 該日已有訂位時後端回 409，訊息會說明有幾位客人
    ElMessage.error(errorMessage(error, '設定失敗'))
  } finally {
    adding.value = false
  }
}

async function remove(row: ClosedDate): Promise<void> {
  try {
    await ElMessageBox.confirm(`確定取消 ${row.closed_date} 的公休？該日的可訂時段會重新開放。`, '取消公休', {
      type: 'warning',
      confirmButtonText: '確定',
      cancelButtonText: '返回',
    })
  } catch {
    return
  }

  try {
    await http.delete(`/admin/stores/${props.storeId}/closed-dates/${row.id}`)
    ElMessage.success('已取消公休')
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '取消失敗'))
  }
}

onMounted(load)
</script>

<template>
  <div v-if="canManage" class="add-row">
    <el-date-picker
      v-model="newDate"
      type="date"
      value-format="YYYY-MM-DD"
      placeholder="選擇日期"
      :disabled-date="isPast"
    />
    <el-input v-model="newReason" placeholder="原因（選填）" maxlength="100" style="width: 220px" />
    <el-button type="primary" :loading="adding" @click="add">新增公休日</el-button>
  </div>

  <el-table v-loading="loading" :data="dates" empty-text="沒有即將到來的公休日">
    <el-table-column prop="closed_date" label="日期" width="140" />
    <el-table-column label="原因">
      <template #default="{ row }">{{ row.reason ?? '—' }}</template>
    </el-table-column>
    <el-table-column v-if="canManage" label="操作" width="100">
      <template #default="{ row }">
        <el-button link type="danger" @click="remove(row)">取消</el-button>
      </template>
    </el-table-column>
  </el-table>
</template>

<style scoped>
.add-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
</style>
