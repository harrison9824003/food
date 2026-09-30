<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { ref, watch } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { Slot, SlotSyncResult } from '@/types/api'
import { todayString } from '@/utils/date'

const props = defineProps<{ storeId: number; canManage: boolean }>()

const date = ref(todayString())
const slots = ref<Slot[]>([])
/** 編輯中的名額，key 為 slot id；沒有值代表未修改。 */
const draft = ref<Record<number, number>>({})
const loading = ref(false)
const generating = ref(false)

async function load(): Promise<void> {
  if (!date.value) {
    slots.value = []
    return
  }

  loading.value = true
  try {
    const { data } = await http.get<Slot[]>(`/admin/stores/${props.storeId}/slots`, { params: { date: date.value } })
    slots.value = data
    draft.value = {}
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入時段失敗'))
  } finally {
    loading.value = false
  }
}

async function saveCapacity(row: Slot): Promise<void> {
  const capacity = draft.value[row.id]
  if (capacity === undefined || capacity === row.capacity) {
    return
  }

  try {
    const { data } = await http.patch<Slot>(`/admin/stores/${props.storeId}/slots/${row.id}`, { capacity })
    Object.assign(row, data)
    delete draft.value[row.id]
    ElMessage.success('已更新名額')
  } catch (error) {
    // 名額低於已訂人數時後端回 409，並說明目前已訂幾位
    ElMessage.error(errorMessage(error, '更新失敗'))
  }
}

async function generate(): Promise<void> {
  generating.value = true
  try {
    const { data } = await http.post<SlotSyncResult>(`/admin/stores/${props.storeId}/slots/generate`)
    ElMessage.success(`新增 ${data.created} 個時段、移除 ${data.deleted} 個`)
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '產生時段失敗'))
  } finally {
    generating.value = false
  }
}

watch(date, load, { immediate: true })
</script>

<template>
  <div class="toolbar">
    <el-date-picker v-model="date" type="date" value-format="YYYY-MM-DD" :clearable="false" />
    <el-button v-if="canManage" :loading="generating" @click="generate">依營業時間重新產生時段</el-button>
  </div>

  <el-table v-loading="loading" :data="slots" empty-text="這天沒有開放的時段（公休、未設定營業時間，或超出開放天數）">
    <el-table-column prop="start_time" label="時段" width="100" />
    <el-table-column label="名額" width="200">
      <template #default="{ row }">
        <el-input-number
          v-if="canManage"
          :model-value="draft[row.id] ?? row.capacity"
          :min="0"
          :max="1000"
          size="small"
          @update:model-value="(v: number | undefined) => (draft[row.id] = v ?? row.capacity)"
        />
        <span v-else>{{ row.capacity }}</span>
      </template>
    </el-table-column>
    <el-table-column prop="booked" label="已訂" width="90" />
    <el-table-column prop="remaining" label="剩餘" width="90" />
    <el-table-column v-if="canManage" label="操作" width="100">
      <template #default="{ row }">
        <el-button
          link
          type="primary"
          :disabled="draft[row.id] === undefined || draft[row.id] === row.capacity"
          @click="saveCapacity(row)"
        >
          儲存
        </el-button>
      </template>
    </el-table-column>
  </el-table>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
</style>
