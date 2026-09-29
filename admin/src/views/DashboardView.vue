<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { ROLE_LABELS } from '@/types/api'

const auth = useAuthStore()

const scope = computed(() => {
  switch (auth.role) {
    case 'hq_admin':
      return '可管理所有門市與所有帳號'
    case 'store_manager':
      return '可管理自己門市的設定、訂單與店員帳號'
    default:
      return '可查看並處理自己門市的訂單'
  }
})
</script>

<template>
  <el-card v-if="auth.user">
    <template #header>歡迎，{{ auth.user.name }}</template>
    <el-descriptions :column="1" border>
      <el-descriptions-item label="角色">{{ ROLE_LABELS[auth.user.role] }}</el-descriptions-item>
      <el-descriptions-item label="所屬門市">{{ auth.user.store?.name ?? '總部（不隸屬單一門市）' }}</el-descriptions-item>
      <el-descriptions-item label="權限範圍">{{ scope }}</el-descriptions-item>
    </el-descriptions>
  </el-card>
</template>
