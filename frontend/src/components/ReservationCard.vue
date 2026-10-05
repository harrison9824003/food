<script setup lang="ts">
import { computed } from 'vue'
import { PAYMENT_STATUS_LABELS } from '@/types/api'
import type { Reservation } from '@/types/api'

const props = defineProps<{ reservation: Reservation }>()

const tagType = computed(() => {
  switch (props.reservation.status) {
    case 'confirmed':
    case 'arrived':
      return 'success'
    case 'pending_payment':
      return 'warning'
    default:
      return 'info'
  }
})
</script>

<template>
  <el-descriptions :column="1" border>
    <el-descriptions-item label="訂位代號">
      <strong class="code">{{ reservation.code }}</strong>
    </el-descriptions-item>
    <el-descriptions-item label="狀態">
      <el-tag :type="tagType">{{ reservation.status_label }}</el-tag>
    </el-descriptions-item>
    <el-descriptions-item label="門市">
      {{ reservation.store.name }}
      <div v-if="reservation.store.address" class="sub">{{ reservation.store.address }}</div>
      <div v-if="reservation.store.phone" class="sub">{{ reservation.store.phone }}</div>
    </el-descriptions-item>
    <el-descriptions-item label="日期時間">{{ reservation.date }}　{{ reservation.time }}</el-descriptions-item>
    <el-descriptions-item label="人數">{{ reservation.party_size }} 人</el-descriptions-item>
    <el-descriptions-item label="姓名">{{ reservation.customer_name }}</el-descriptions-item>
    <el-descriptions-item v-if="reservation.note" label="備註">{{ reservation.note }}</el-descriptions-item>
    <el-descriptions-item label="訂金">
      <template v-if="reservation.deposit_amount > 0">
        ${{ reservation.deposit_amount }}
        <el-tag size="small" class="pay-tag">{{ PAYMENT_STATUS_LABELS[reservation.payment_status] ?? reservation.payment_status }}</el-tag>
      </template>
      <template v-else>免訂金</template>
    </el-descriptions-item>
    <el-descriptions-item v-if="reservation.items?.length" label="預點餐點">
      <div v-for="item in reservation.items" :key="item.name">{{ item.name }} × {{ item.quantity }}</div>
      <div class="sub">預點不計費，用餐時結帳</div>
    </el-descriptions-item>
  </el-descriptions>
</template>

<style scoped>
.code {
  font-size: 22px;
  letter-spacing: 2px;
}
.sub {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}
.pay-tag {
  margin-left: 8px;
}
</style>
