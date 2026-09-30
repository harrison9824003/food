<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import StoreBasicForm from '@/components/store/StoreBasicForm.vue'
import { useAuthStore } from '@/stores/auth'
import type { Store } from '@/types/api'

const auth = useAuthStore()
const router = useRouter()

const stores = ref<Store[]>([])
const loading = ref(false)
const createVisible = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    // 後端依角色限縮：總部拿到全部，店長、店員只會拿到自己的門市
    const { data } = await http.get<Store[]>('/admin/stores')
    stores.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入門市失敗'))
  } finally {
    loading.value = false
  }
}

function open(store: Store): void {
  void router.push({ name: 'store-settings', params: { id: store.id } })
}

async function onCreated(store: Store): Promise<void> {
  createVisible.value = false
  await load()
  open(store) // 新門市還沒有營業時間，直接帶去設定
}

onMounted(load)
</script>

<template>
  <el-card>
    <template #header>
      <div class="header">
        <span>門市</span>
        <el-button v-if="auth.isHq" type="primary" @click="createVisible = true">新增門市</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="stores" empty-text="沒有資料" @row-click="open">
      <el-table-column prop="name" label="門市" min-width="140" />
      <el-table-column prop="address" label="地址" min-width="200" />
      <el-table-column prop="phone" label="電話" width="140" />
      <el-table-column label="每人訂金" width="110">
        <template #default="{ row }">{{ row.deposit_per_person > 0 ? `$${row.deposit_per_person}` : '免訂金' }}</template>
      </el-table-column>
      <el-table-column label="狀態" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? '營業中' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="100">
        <template #default="{ row }">
          <el-button link type="primary" @click.stop="open(row)">
            {{ auth.canManageStore(row.id) ? '設定' : '檢視' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="createVisible" title="新增門市" width="640px" destroy-on-close>
    <StoreBasicForm :store="null" can-edit can-edit-core @saved="onCreated" />
  </el-dialog>
</template>

<style scoped>
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
:deep(.el-table__row) {
  cursor: pointer;
}
</style>
