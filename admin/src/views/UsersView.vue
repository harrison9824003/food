<script setup lang="ts">
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import UserFormDialog from '@/components/users/UserFormDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { ROLE_LABELS } from '@/types/api'
import type { Paginated, Store, User } from '@/types/api'

const auth = useAuthStore()

const users = ref<User[]>([])
const stores = ref<Store[]>([])
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(20)

const dialogVisible = ref(false)
const editing = ref<User | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    // 後端依登入者角色自動限縮範圍：店長只會拿到自己門市的帳號
    const { data } = await http.get<Paginated<User>>('/admin/users', { params: { page: page.value } })
    users.value = data.data
    total.value = data.meta.total
    perPage.value = data.meta.per_page
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入帳號失敗'))
  } finally {
    loading.value = false
  }
}

async function loadStores(): Promise<void> {
  try {
    const { data } = await http.get<Store[]>('/admin/stores')
    stores.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入門市失敗'))
  }
}

/** 是否顯示編輯／停用按鈕；只是體驗，真正的權限在後端 UserPolicy。 */
function canManage(target: User): boolean {
  if (auth.isHq) {
    return true
  }
  return auth.role === 'store_manager' && target.role === 'staff' && target.store_id === auth.user?.store_id
}

function openCreate(): void {
  editing.value = null
  dialogVisible.value = true
}

function openEdit(user: User): void {
  editing.value = user
  dialogVisible.value = true
}

async function toggleActive(user: User): Promise<void> {
  const next = !user.is_active
  if (!next) {
    try {
      await ElMessageBox.confirm(`確定停用「${user.name}」？對方會立即被登出，無法再登入。`, '停用帳號', {
        type: 'warning',
        confirmButtonText: '停用',
        cancelButtonText: '返回',
      })
    } catch {
      return
    }
  }

  try {
    await http.patch(`/admin/users/${user.id}`, { is_active: next })
    ElMessage.success(next ? '已啟用' : '已停用')
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '更新失敗'))
  }
}

function onPageChange(next: number): void {
  page.value = next
  void load()
}

onMounted(() => {
  void load()
  void loadStores()
})
</script>

<template>
  <el-card>
    <template #header>
      <div class="header">
        <span>帳號管理</span>
        <el-button type="primary" @click="openCreate">新增帳號</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="users" empty-text="沒有資料" stripe>
      <el-table-column prop="name" label="姓名" min-width="120" />
      <el-table-column prop="email" label="Email" min-width="200" />
      <el-table-column label="角色" width="120">
        <template #default="{ row }">{{ ROLE_LABELS[row.role as keyof typeof ROLE_LABELS] }}</template>
      </el-table-column>
      <el-table-column label="門市" width="120">
        <template #default="{ row }">{{ row.store?.name ?? '總部' }}</template>
      </el-table-column>
      <el-table-column label="狀態" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? '啟用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150">
        <template #default="{ row }">
          <template v-if="canManage(row)">
            <el-button link type="primary" @click="openEdit(row)">編輯</el-button>
            <!-- 不能停用自己：後端也會擋，這裡直接不給按鈕 -->
            <el-button v-if="row.id !== auth.user?.id" link :type="row.is_active ? 'danger' : 'success'" @click="toggleActive(row)">
              {{ row.is_active ? '停用' : '啟用' }}
            </el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-if="total > perPage"
      class="pager"
      layout="prev, pager, next"
      :total="total"
      :page-size="perPage"
      :current-page="page"
      @current-change="onPageChange"
    />
  </el-card>

  <UserFormDialog v-model="dialogVisible" :user="editing" :stores="stores" @saved="load" />
</template>

<style scoped>
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
