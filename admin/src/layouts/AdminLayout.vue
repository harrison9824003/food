<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ROLE_LABELS } from '@/types/api'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const roleLabel = computed(() => (auth.role ? ROLE_LABELS[auth.role] : ''))
const storeLabel = computed(() => auth.user?.store?.name ?? '全部門市')

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <el-container class="layout">
    <el-aside width="200px" class="aside">
      <div class="brand">門市管理後台</div>
      <!-- 選單依角色顯示；只是為了體驗，真正的權限在後端 -->
      <el-menu :default-active="route.path" router class="menu">
        <el-menu-item index="/">總覽</el-menu-item>
        <el-menu-item v-if="auth.canManageUsers" index="/users">帳號管理</el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="header">
        <span class="who">{{ storeLabel }}　{{ auth.user?.name }}（{{ roleLabel }}）</span>
        <el-button link type="primary" @click="logout">登出</el-button>
      </el-header>
      <el-main>
        <RouterView />
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.layout {
  min-height: 100%;
}
.aside {
  background: #fff;
  border-right: 1px solid #e4e7ed;
}
.brand {
  height: 56px;
  line-height: 56px;
  text-align: center;
  font-weight: 600;
  border-bottom: 1px solid #e4e7ed;
}
.menu {
  border-right: none;
}
.header {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
}
.who {
  color: #606266;
}
</style>
