<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'
import type { Calendar } from '@/types/api'

const MAX_PARTY = 12

const booking = useBookingStore()
const router = useRouter()

const calendar = ref<Calendar | null>(null)
const loading = ref(false)
const date = ref(booking.date)
const partySize = ref(booking.partySize)

// date → 是否營業。不在開放期間內、或公休的日子都不能選
const openDates = computed(() => new Map(calendar.value?.dates.map((d) => [d.date, d.open]) ?? []))

function toDateString(d: Date): string {
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${month}-${day}`
}

function disabledDate(d: Date): boolean {
  return openDates.value.get(toDateString(d)) !== true
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<Calendar>(`/stores/${booking.storeId}/calendar`)
    calendar.value = data
    // 之前選的日期若已不可選（例如隔天重新整理），清掉
    if (date.value && openDates.value.get(date.value) !== true) {
      date.value = ''
    }
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入日期失敗'))
  } finally {
    loading.value = false
  }
}

function next(): void {
  if (!date.value) {
    ElMessage.warning('請選擇日期')
    return
  }
  booking.setDateAndParty(date.value, partySize.value)
  void router.push({ name: 'slot' })
}

onMounted(load)
</script>

<template>
  <BookingSteps :active="1" />

  <h2>{{ booking.storeName }}</h2>

  <el-form label-position="top" v-loading="loading">
    <el-form-item label="用餐人數">
      <el-input-number v-model="partySize" :min="1" :max="MAX_PARTY" />
      <span class="hint">共 {{ MAX_PARTY }} 人以內；更多人數請來電洽詢</span>
    </el-form-item>

    <el-form-item label="用餐日期">
      <el-date-picker
        v-model="date"
        type="date"
        value-format="YYYY-MM-DD"
        placeholder="選擇日期"
        :disabled-date="disabledDate"
        :clearable="false"
        style="width: 100%"
      />
      <div v-if="calendar" class="hint">可預約 {{ calendar.from }} 至 {{ calendar.to }}，公休日不可選</div>
    </el-form-item>
  </el-form>

  <div class="actions">
    <el-button @click="router.push({ name: 'store' })">上一步</el-button>
    <el-button type="primary" @click="next">下一步</el-button>
  </div>
</template>

<style scoped>
.hint {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
.actions {
  display: flex;
  justify-content: space-between;
  margin-top: 24px;
}
</style>
