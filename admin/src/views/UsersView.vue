<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import { ROLE_LABELS } from '@/types/api'
import type { Paginated, User } from '@/types/api'

const users = ref<User[]>([])
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(20)

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

function onPageChange(next: number): void {
  page.value = next
  void load()
}

onMounted(load)
</script>

<template>
  <el-card>
    <template #header>帳號管理</template>

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
</template>

<style scoped>
.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
