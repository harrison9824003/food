<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import { WEEKDAY_LABELS } from '@/types/api'
import type { BusinessHour, SlotSyncResult } from '@/types/api'

const props = defineProps<{ storeId: number; canManage: boolean }>()

const hours = ref<BusinessHour[]>([])
const loading = ref(false)
const saving = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<BusinessHour[]>(`/admin/stores/${props.storeId}/business-hours`)
    hours.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入營業時間失敗'))
  } finally {
    loading.value = false
  }
}

function toggleClosed(hour: BusinessHour, closed: boolean): void {
  hour.is_closed = closed
  // 從公休切回營業時，補上常見的預設時間，省得每格重填
  if (!closed && !hour.open_time) {
    hour.open_time = '11:00'
    hour.close_time = '21:00'
  }
}

async function save(): Promise<void> {
  saving.value = true
  try {
    const { data } = await http.put<{ hours: BusinessHour[]; slots: SlotSyncResult }>(
      `/admin/stores/${props.storeId}/business-hours`,
      { hours: hours.value },
    )
    hours.value = data.hours
    ElMessage.success(`已儲存：新增 ${data.slots.created} 個時段、移除 ${data.slots.deleted} 個未被預約的時段`)
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <el-table v-loading="loading" :data="hours" :show-header="true">
    <el-table-column label="星期" width="90">
      <template #default="{ row }">{{ WEEKDAY_LABELS[row.weekday] }}</template>
    </el-table-column>
    <el-table-column label="營業" width="90">
      <template #default="{ row }">
        <el-switch
          :model-value="!row.is_closed"
          :disabled="!canManage"
          @update:model-value="(v: string | number | boolean) => toggleClosed(row, !v)"
        />
      </template>
    </el-table-column>
    <el-table-column label="開店" width="160">
      <template #default="{ row }">
        <el-time-select
          v-if="!row.is_closed"
          v-model="row.open_time"
          :disabled="!canManage"
          start="06:00"
          step="00:30"
          end="23:30"
          :clearable="false"
          placeholder="開店"
        />
        <span v-else class="muted">公休</span>
      </template>
    </el-table-column>
    <el-table-column label="打烊" width="160">
      <template #default="{ row }">
        <el-time-select
          v-if="!row.is_closed"
          v-model="row.close_time"
          :disabled="!canManage"
          start="06:30"
          step="00:30"
          end="23:30"
          :clearable="false"
          placeholder="打烊"
        />
      </template>
    </el-table-column>
    <el-table-column label="時段間隔">
      <template #default="{ row }">
        <el-select v-if="!row.is_closed" v-model="row.slot_minutes" :disabled="!canManage" style="width: 110px">
          <el-option :value="15" label="15 分鐘" />
          <el-option :value="30" label="30 分鐘" />
          <el-option :value="60" label="60 分鐘" />
        </el-select>
      </template>
    </el-table-column>
  </el-table>

  <div v-if="canManage" class="actions">
    <el-button type="primary" :loading="saving" @click="save">儲存營業時間</el-button>
    <span class="muted">儲存後會立即調整未來的可訂時段；已有人訂位的時段不會被移除。</span>
  </div>
</template>

<style scoped>
.actions {
  margin-top: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.muted {
  color: #909399;
  font-size: 12px;
}
</style>
