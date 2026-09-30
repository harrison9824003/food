<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import BusinessHoursPanel from '@/components/store/BusinessHoursPanel.vue'
import ClosedDatesPanel from '@/components/store/ClosedDatesPanel.vue'
import SlotsPanel from '@/components/store/SlotsPanel.vue'
import StoreBasicForm from '@/components/store/StoreBasicForm.vue'
import TablesPanel from '@/components/store/TablesPanel.vue'
import { useAuthStore } from '@/stores/auth'
import type { Store } from '@/types/api'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const store = ref<Store | null>(null)
const loading = ref(false)
const activeTab = ref('basic')

const storeId = computed(() => Number(route.params.id))
// 只控制畫面上能不能操作；真正的權限由後端 StorePolicy 把關
const canManage = computed(() => auth.canManageStore(storeId.value))

async function load(): Promise<void> {
  loading.value = true
  store.value = null
  try {
    const { data } = await http.get<Store>(`/admin/stores/${storeId.value}`)
    store.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入門市失敗'))
    void router.replace({ name: 'stores' })
  } finally {
    loading.value = false
  }
}

function onSaved(updated: Store): void {
  store.value = updated
}

watch(storeId, load, { immediate: true })
</script>

<template>
  <el-card v-loading="loading">
    <template #header>
      <div class="header">
        <el-button link @click="router.push({ name: 'stores' })">← 門市列表</el-button>
        <span class="title">{{ store?.name }}</span>
        <el-tag v-if="!canManage" type="info">唯讀</el-tag>
      </div>
    </template>

    <el-tabs v-if="store" v-model="activeTab" lazy>
      <el-tab-pane label="基本資料與開放規則" name="basic">
        <StoreBasicForm :store="store" :can-edit="canManage" :can-edit-core="auth.isHq" @saved="onSaved" />
      </el-tab-pane>
      <el-tab-pane label="營業時間" name="hours">
        <BusinessHoursPanel :store-id="store.id" :can-manage="canManage" />
      </el-tab-pane>
      <el-tab-pane label="公休日" name="closed">
        <ClosedDatesPanel :store-id="store.id" :can-manage="canManage" />
      </el-tab-pane>
      <el-tab-pane label="桌位" name="tables">
        <TablesPanel :store-id="store.id" :can-manage="canManage" />
      </el-tab-pane>
      <el-tab-pane label="時段名額" name="slots">
        <SlotsPanel :store-id="store.id" :can-manage="canManage" />
      </el-tab-pane>
    </el-tabs>
  </el-card>
</template>

<style scoped>
.header {
  display: flex;
  align-items: center;
  gap: 12px;
}
.title {
  font-weight: 600;
}
</style>
