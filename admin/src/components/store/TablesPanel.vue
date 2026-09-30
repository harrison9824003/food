<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage } from 'element-plus'
import { onMounted, reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import type { DiningTable } from '@/types/api'

const props = defineProps<{ storeId: number; canManage: boolean }>()

const tables = ref<DiningTable[]>([])
const loading = ref(false)

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const formRef = ref<FormInstance>()
const form = reactive({ code: '', min_seats: 1, max_seats: 4, is_combinable: false })

const rules: FormRules = {
  code: [{ required: true, message: '請輸入桌號', trigger: 'blur' }],
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<DiningTable[]>(`/admin/stores/${props.storeId}/tables`)
    tables.value = data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入桌位失敗'))
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editingId.value = null
  Object.assign(form, { code: '', min_seats: 1, max_seats: 4, is_combinable: false })
  dialogVisible.value = true
}

function openEdit(row: DiningTable): void {
  editingId.value = row.id
  Object.assign(form, {
    code: row.code,
    min_seats: row.min_seats,
    max_seats: row.max_seats,
    is_combinable: row.is_combinable,
  })
  dialogVisible.value = true
}

async function save(): Promise<void> {
  if (!(await formRef.value?.validate().catch(() => false))) {
    return
  }

  saving.value = true
  try {
    if (editingId.value === null) {
      await http.post(`/admin/stores/${props.storeId}/tables`, form)
    } else {
      await http.patch(`/admin/stores/${props.storeId}/tables/${editingId.value}`, form)
    }
    ElMessage.success('已儲存')
    dialogVisible.value = false
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    saving.value = false
  }
}

/** 桌位不刪除（歷史訂位會參照），改以停用讓它不再參與新訂位。 */
async function toggleActive(row: DiningTable, active: boolean): Promise<void> {
  try {
    await http.patch(`/admin/stores/${props.storeId}/tables/${row.id}`, { is_active: active })
    row.is_active = active
  } catch (error) {
    ElMessage.error(errorMessage(error, '更新失敗'))
  }
}

onMounted(load)
</script>

<template>
  <div v-if="canManage" class="toolbar">
    <el-button type="primary" @click="openCreate">新增桌位</el-button>
  </div>

  <el-table v-loading="loading" :data="tables" empty-text="尚未設定桌位">
    <el-table-column prop="code" label="桌號" width="110" />
    <el-table-column label="可坐人數" width="120">
      <template #default="{ row }">{{ row.min_seats }} – {{ row.max_seats }} 人</template>
    </el-table-column>
    <el-table-column label="可併桌" width="100">
      <template #default="{ row }">
        <el-tag :type="row.is_combinable ? 'success' : 'info'">{{ row.is_combinable ? '可' : '否' }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column label="啟用" width="100">
      <template #default="{ row }">
        <el-switch
          :model-value="row.is_active"
          :disabled="!canManage"
          @update:model-value="(v: string | number | boolean) => toggleActive(row, Boolean(v))"
        />
      </template>
    </el-table-column>
    <el-table-column v-if="canManage" label="操作" width="100">
      <template #default="{ row }">
        <el-button link type="primary" @click="openEdit(row)">編輯</el-button>
      </template>
    </el-table-column>
  </el-table>

  <el-dialog v-model="dialogVisible" :title="editingId === null ? '新增桌位' : '編輯桌位'" width="420px">
    <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" @submit.prevent="save">
      <el-form-item label="桌號" prop="code">
        <el-input v-model="form.code" maxlength="20" />
      </el-form-item>
      <el-form-item label="最少人數">
        <el-input-number v-model="form.min_seats" :min="1" :max="50" />
      </el-form-item>
      <el-form-item label="最多人數">
        <el-input-number v-model="form.max_seats" :min="1" :max="50" />
      </el-form-item>
      <el-form-item label="可併桌">
        <el-switch v-model="form.is_combinable" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="save">儲存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar {
  margin-bottom: 16px;
}
</style>
