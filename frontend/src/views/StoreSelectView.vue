<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'
import type { StoreInfo } from '@/types/api'

const booking = useBookingStore()
const router = useRouter()

const stores = ref<StoreInfo[]>([])
const loading = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<StoreInfo[]>('/stores')
    stores.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入門市失敗'))
  } finally {
    loading.value = false
  }
}

function choose(store: StoreInfo): void {
  booking.selectStore(store.id, store.name, store.deposit_per_person)
  void router.push({ name: 'date' })
}

onMounted(load)
</script>

<template>
  <BookingSteps :active="0" />

  <h2>選擇門市</h2>
  <div v-loading="loading" class="list">
    <el-empty v-if="!loading && stores.length === 0" description="目前沒有開放訂位的門市" />

    <el-card
      v-for="store in stores"
      :key="store.id"
      shadow="hover"
      class="store"
      :class="{ selected: booking.storeId === store.id }"
      @click="choose(store)"
    >
      <div class="name">{{ store.name }}</div>
      <div class="meta">{{ store.address ?? '—' }}</div>
      <div class="meta">
        <span v-if="store.phone">{{ store.phone }}　</span>
        <el-tag v-if="store.deposit_per_person > 0" type="warning" size="small">每人訂金 ${{ store.deposit_per_person }}</el-tag>
        <el-tag v-else type="success" size="small">免訂金</el-tag>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.list {
  display: grid;
  gap: 12px;
  min-height: 80px;
}
.store {
  cursor: pointer;
}
.store.selected {
  border-color: #409eff;
}
.name {
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 6px;
}
.meta {
  color: #606266;
  font-size: 14px;
  margin-top: 4px;
}
</style>
