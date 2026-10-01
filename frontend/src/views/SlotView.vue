<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'
import { BLOCK_REASON_LABELS } from '@/types/api'
import type { SlotAvailability } from '@/types/api'

const booking = useBookingStore()
const router = useRouter()

const slots = ref<SlotAvailability[]>([])
const loading = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<{ slots: SlotAvailability[] }>(`/stores/${booking.storeId}/availability`, {
      params: { date: booking.date, party_size: booking.partySize },
    })
    slots.value = data.slots

    // 上次選的時段若現在已不可訂（客滿、過了截止時間），清掉，避免帶著失效的選擇往下走
    const chosen = slots.value.find((s) => s.id === booking.slotId)
    if (booking.slotId !== null && !chosen?.bookable) {
      booking.slotId = null
      booking.slotTime = ''
    }
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入時段失敗'))
  } finally {
    loading.value = false
  }
}

function choose(slot: SlotAvailability): void {
  if (!slot.bookable) {
    return
  }
  booking.selectSlot(slot.id, slot.start_time)
}

function next(): void {
  if (booking.slotId === null) {
    ElMessage.warning('請選擇時段')
    return
  }
  void router.push({ name: 'info' })
}

onMounted(load)
</script>

<template>
  <BookingSteps :active="2" />

  <h2>{{ booking.storeName }}　{{ booking.date }}　{{ booking.partySize }} 人</h2>

  <div v-loading="loading" class="grid">
    <el-empty v-if="!loading && slots.length === 0" description="這天沒有開放的時段，請換一天試試" />

    <button
      v-for="slot in slots"
      :key="slot.id"
      type="button"
      class="slot"
      :class="{ selected: booking.slotId === slot.id }"
      :disabled="!slot.bookable"
      @click="choose(slot)"
    >
      <span class="time">{{ slot.start_time }}</span>
      <span v-if="slot.bookable" class="left">剩 {{ slot.remaining }} 位</span>
      <span v-else class="left off">{{ BLOCK_REASON_LABELS[slot.reason ?? ''] ?? '不可訂' }}</span>
    </button>
  </div>

  <div class="actions">
    <el-button @click="router.push({ name: 'date' })">上一步</el-button>
    <el-button type="primary" :disabled="booking.slotId === null" @click="next">下一步</el-button>
  </div>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
  gap: 10px;
  min-height: 80px;
}
.slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 12px 6px;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
}
.slot:hover:not(:disabled) {
  border-color: #409eff;
}
.slot.selected {
  background: #ecf5ff;
  border-color: #409eff;
}
.slot:disabled {
  background: #f5f7fa;
  color: #c0c4cc;
  cursor: not-allowed;
}
.time {
  font-size: 18px;
  font-weight: 600;
}
.left {
  font-size: 12px;
  color: #67c23a;
}
.left.off {
  color: #c0c4cc;
}
.actions {
  display: flex;
  justify-content: space-between;
  margin-top: 24px;
}
</style>
