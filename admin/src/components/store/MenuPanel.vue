<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, onMounted, reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/http'
import { MENU_IMAGE_LIMITS } from '@/types/api'
import type { MenuCategory, MenuItem } from '@/types/api'

const props = defineProps<{ storeId: number; canManage: boolean }>()

const base = `/admin/stores/${props.storeId}`

const categories = ref<MenuCategory[]>([])
const items = ref<MenuItem[]>([])
const loading = ref(false)

/** 'all' = 全部，'none' = 未分類，其餘為分類 ID */
const filter = ref<'all' | 'none' | number>('all')

const visibleItems = computed(() => {
  if (filter.value === 'all') {
    return items.value
  }
  return items.value.filter((i) => (filter.value === 'none' ? i.category_id === null : i.category_id === filter.value))
})

const uncategorizedCount = computed(() => items.value.filter((i) => i.category_id === null).length)

function categoryName(id: number | null): string {
  return categories.value.find((c) => c.id === id)?.name ?? '未分類'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [cats, list] = await Promise.all([
      http.get<MenuCategory[]>(`${base}/menu-categories`),
      http.get<MenuItem[]>(`${base}/menu-items`),
    ])
    categories.value = cats.data
    items.value = list.data
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入菜單失敗'))
  } finally {
    loading.value = false
  }
}

// ── 分類 ────────────────────────────────────────────────────────────────

const categoryDialog = ref(false)
const categoryEditingId = ref<number | null>(null)
const categoryForm = reactive({ name: '', sort_order: 0, is_active: true })
const categoryFormRef = ref<FormInstance>()
const categoryRules: FormRules = { name: [{ required: true, message: '請輸入分類名稱', trigger: 'blur' }] }
const savingCategory = ref(false)

function openCategory(category?: MenuCategory): void {
  categoryEditingId.value = category?.id ?? null
  Object.assign(categoryForm, category
    ? { name: category.name, sort_order: category.sort_order, is_active: category.is_active }
    : { name: '', sort_order: (categories.value.at(-1)?.sort_order ?? -1) + 1, is_active: true })
  categoryDialog.value = true
}

async function saveCategory(): Promise<void> {
  if (!(await categoryFormRef.value?.validate().catch(() => false))) {
    return
  }
  savingCategory.value = true
  try {
    if (categoryEditingId.value === null) {
      await http.post(`${base}/menu-categories`, categoryForm)
    } else {
      await http.patch(`${base}/menu-categories/${categoryEditingId.value}`, categoryForm)
    }
    ElMessage.success('已儲存')
    categoryDialog.value = false
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    savingCategory.value = false
  }
}

async function removeCategory(category: MenuCategory): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `確定刪除分類「${category.name}」？底下的 ${category.items_count ?? 0} 個品項不會被刪除，會改為「未分類」。`,
      '刪除分類',
      { type: 'warning', confirmButtonText: '刪除', cancelButtonText: '返回' },
    )
  } catch {
    return
  }
  try {
    await http.delete(`${base}/menu-categories/${category.id}`)
    ElMessage.success('已刪除分類')
    if (filter.value === category.id) {
      filter.value = 'all'
    }
    await load()
  } catch (error) {
    ElMessage.error(errorMessage(error, '刪除失敗'))
  }
}

// ── 品項 ────────────────────────────────────────────────────────────────

const itemDialog = ref(false)
const itemEditing = ref<MenuItem | null>(null)
const itemForm = reactive({
  name: '',
  category_id: null as number | null,
  price: 0,
  description: '',
  sort_order: 0,
  is_active: true,
  is_sold_out: false,
})
const itemFormRef = ref<FormInstance>()
const itemRules: FormRules = { name: [{ required: true, message: '請輸入品名', trigger: 'blur' }] }
const savingItem = ref(false)

function openItem(item?: MenuItem): void {
  itemEditing.value = item ?? null
  Object.assign(itemForm, item
    ? {
        name: item.name,
        category_id: item.category_id,
        price: item.price,
        description: item.description ?? '',
        sort_order: item.sort_order,
        is_active: item.is_active,
        is_sold_out: item.is_sold_out,
      }
    : {
        name: '',
        category_id: typeof filter.value === 'number' ? filter.value : null,
        price: 0,
        description: '',
        sort_order: (items.value.at(-1)?.sort_order ?? -1) + 1,
        is_active: true,
        is_sold_out: false,
      })
  itemDialog.value = true
}

/** 取代清單中的同一個品項（圖片上傳、刪除、排序後後端會回傳最新的品項）。 */
function replaceItem(updated: MenuItem): void {
  const index = items.value.findIndex((i) => i.id === updated.id)
  if (index >= 0) {
    items.value[index] = updated
  } else {
    items.value.push(updated)
  }
  if (itemEditing.value?.id === updated.id) {
    itemEditing.value = updated
  }
}

async function saveItem(): Promise<void> {
  if (!(await itemFormRef.value?.validate().catch(() => false))) {
    return
  }
  savingItem.value = true
  try {
    const payload = { ...itemForm, description: itemForm.description || null }
    if (itemEditing.value === null) {
      const { data } = await http.post<MenuItem>(`${base}/menu-items`, payload)
      replaceItem(data)
      // 新品項建立後留在視窗內，讓管理員接著上傳圖片（圖片需要品項已存在）
      itemEditing.value = data
      ElMessage.success('已建立品項，接著可以上傳圖片')
    } else {
      const { data } = await http.patch<MenuItem>(`${base}/menu-items/${itemEditing.value.id}`, payload)
      replaceItem(data)
      ElMessage.success('已儲存')
      itemDialog.value = false
    }
    await load() // 分類的品項數量也要更新
  } catch (error) {
    ElMessage.error(errorMessage(error, '儲存失敗'))
  } finally {
    savingItem.value = false
  }
}

/** 列表上的快速開關（上架、售完）。 */
async function toggle(item: MenuItem, field: 'is_active' | 'is_sold_out', value: boolean): Promise<void> {
  try {
    const { data } = await http.patch<MenuItem>(`${base}/menu-items/${item.id}`, { [field]: value })
    replaceItem(data)
  } catch (error) {
    ElMessage.error(errorMessage(error, '更新失敗'))
  }
}

// ── 圖片 ────────────────────────────────────────────────────────────────

const fileInput = ref<HTMLInputElement>()
const uploading = ref(false)
const currentImages = computed(() => itemEditing.value?.images ?? [])
const remainingSlots = computed(() => MENU_IMAGE_LIMITS.maxImages - currentImages.value.length)

async function onFilesSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = '' // 讓同一個檔案可以再選一次

  if (files.length === 0 || itemEditing.value === null) {
    return
  }

  // 這些只是讓使用者早點知道，伺服器會再完整檢查一次（不能相信前端）
  if (files.length > remainingSlots.value) {
    ElMessage.warning(`最多 ${MENU_IMAGE_LIMITS.maxImages} 張，還能再上傳 ${Math.max(0, remainingSlots.value)} 張`)
    return
  }
  const tooBig = files.find((f) => f.size > MENU_IMAGE_LIMITS.maxMegabytes * 1024 * 1024)
  if (tooBig) {
    ElMessage.warning(`「${tooBig.name}」超過 ${MENU_IMAGE_LIMITS.maxMegabytes}MB`)
    return
  }

  const form = new FormData()
  files.forEach((file) => form.append('images[]', file))

  uploading.value = true
  try {
    const { data } = await http.post<MenuItem>(`${base}/menu-items/${itemEditing.value.id}/images`, form)
    replaceItem(data)
    ElMessage.success(`已上傳 ${files.length} 張圖片`)
  } catch (error) {
    // 整批全有或全無：有任何一張不合格，這一批都不會存
    ElMessage.error(errorMessage(error, '上傳失敗'))
  } finally {
    uploading.value = false
  }
}

async function deleteImage(imageId: number): Promise<void> {
  if (itemEditing.value === null) {
    return
  }
  try {
    await ElMessageBox.confirm('確定刪除這張圖片？', '刪除圖片', { type: 'warning', confirmButtonText: '刪除', cancelButtonText: '返回' })
  } catch {
    return
  }
  try {
    const { data } = await http.delete<MenuItem>(`${base}/menu-items/${itemEditing.value.id}/images/${imageId}`)
    replaceItem(data)
  } catch (error) {
    ElMessage.error(errorMessage(error, '刪除失敗'))
  }
}

/** 把圖片往前或往後移一格；第一張是顧客看到的封面。 */
async function moveImage(index: number, delta: -1 | 1): Promise<void> {
  if (itemEditing.value === null) {
    return
  }
  const ids = currentImages.value.map((i) => i.id)
  const target = index + delta
  if (target < 0 || target >= ids.length) {
    return
  }
  ;[ids[index], ids[target]] = [ids[target]!, ids[index]!]

  try {
    const { data } = await http.put<MenuItem>(`${base}/menu-items/${itemEditing.value.id}/images/order`, { ids })
    replaceItem(data)
  } catch (error) {
    // 清單過期（別人剛上傳或刪除）時後端會拒絕，重新載入最新狀態
    ElMessage.error(errorMessage(error, '排序失敗'))
    await load()
    itemEditing.value = items.value.find((i) => i.id === itemEditing.value?.id) ?? null
  }
}

onMounted(load)
</script>

<template>
  <p class="hint">顧客訂位時可預點，<strong>預點不計費</strong>，只是讓門市提早備餐；訂金仍是「每人訂金 × 人數」。</p>

  <div v-loading="loading" class="layout">
    <!-- 分類 -->
    <aside class="side">
      <div class="side-head">
        <span>分類</span>
        <el-button v-if="canManage" link type="primary" @click="openCategory()">新增</el-button>
      </div>
      <ul class="cats">
        <li :class="{ active: filter === 'all' }" @click="filter = 'all'">全部<span class="count">{{ items.length }}</span></li>
        <li v-for="c in categories" :key="c.id" :class="{ active: filter === c.id }" @click="filter = c.id">
          <span class="cat-name">{{ c.name }}<el-tag v-if="!c.is_active" size="small" type="info" class="tag">停用</el-tag></span>
          <span class="count">{{ c.items_count }}</span>
          <span v-if="canManage" class="ops" @click.stop>
            <el-button link size="small" type="primary" @click="openCategory(c)">編輯</el-button>
            <el-button link size="small" type="danger" @click="removeCategory(c)">刪除</el-button>
          </span>
        </li>
        <li :class="{ active: filter === 'none' }" @click="filter = 'none'">未分類<span class="count">{{ uncategorizedCount }}</span></li>
      </ul>
    </aside>

    <!-- 品項 -->
    <section class="main">
      <div v-if="canManage" class="toolbar">
        <el-button type="primary" @click="openItem()">新增品項</el-button>
      </div>

      <el-table :data="visibleItems" empty-text="這個分類還沒有品項">
        <el-table-column label="圖片" width="90">
          <template #default="{ row }">
            <el-image v-if="row.images.length" :src="row.images[0].thumb_url" class="thumb" fit="cover" />
            <div v-else class="thumb empty">無圖</div>
          </template>
        </el-table-column>
        <el-table-column label="品名" min-width="180">
          <template #default="{ row }">
            <div class="name">{{ row.name }}</div>
            <div v-if="row.description" class="desc">{{ row.description }}</div>
          </template>
        </el-table-column>
        <el-table-column label="分類" width="100">
          <template #default="{ row }">{{ categoryName(row.category_id) }}</template>
        </el-table-column>
        <el-table-column label="價格" width="90">
          <template #default="{ row }">${{ row.price }}</template>
        </el-table-column>
        <el-table-column label="圖片數" width="80">
          <template #default="{ row }">{{ row.images.length }}</template>
        </el-table-column>
        <el-table-column label="今日售完" width="95">
          <template #default="{ row }">
            <el-switch
              :model-value="row.is_sold_out"
              :disabled="!canManage"
              @update:model-value="(v: string | number | boolean) => toggle(row, 'is_sold_out', Boolean(v))"
            />
          </template>
        </el-table-column>
        <el-table-column label="上架" width="80">
          <template #default="{ row }">
            <el-switch
              :model-value="row.is_active"
              :disabled="!canManage"
              @update:model-value="(v: string | number | boolean) => toggle(row, 'is_active', Boolean(v))"
            />
          </template>
        </el-table-column>
        <el-table-column v-if="canManage" label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="openItem(row)">編輯</el-button>
          </template>
        </el-table-column>
      </el-table>
      <p class="hint small">「今日售完」仍會顯示在菜單上但不能預點；「上架」關閉則完全不顯示。</p>
    </section>
  </div>

  <!-- 分類對話框 -->
  <el-dialog v-model="categoryDialog" :title="categoryEditingId === null ? '新增分類' : '編輯分類'" width="400px">
    <el-form ref="categoryFormRef" :model="categoryForm" :rules="categoryRules" label-width="80px" @submit.prevent="saveCategory">
      <el-form-item label="名稱" prop="name">
        <el-input v-model="categoryForm.name" maxlength="50" />
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="categoryForm.sort_order" :min="0" :max="9999" />
        <span class="hint small inline">數字小的在前</span>
      </el-form-item>
      <el-form-item label="啟用">
        <el-switch v-model="categoryForm.is_active" />
        <span class="hint small inline">停用後，這個分類與底下的品項都不會顯示給顧客</span>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="categoryDialog = false">取消</el-button>
      <el-button type="primary" :loading="savingCategory" @click="saveCategory">儲存</el-button>
    </template>
  </el-dialog>

  <!-- 品項對話框 -->
  <el-dialog v-model="itemDialog" :title="itemEditing === null ? '新增品項' : `編輯品項：${itemEditing.name}`" width="620px">
    <el-form ref="itemFormRef" :model="itemForm" :rules="itemRules" label-width="80px" @submit.prevent="saveItem">
      <el-form-item label="品名" prop="name">
        <el-input v-model="itemForm.name" maxlength="100" />
      </el-form-item>
      <el-form-item label="分類">
        <el-select v-model="itemForm.category_id" clearable placeholder="未分類" style="width: 100%">
          <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="價格">
        <el-input-number v-model="itemForm.price" :min="0" :max="100000" />
      </el-form-item>
      <el-form-item label="說明">
        <el-input v-model="itemForm.description" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="食材、份量、口味…" />
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="itemForm.sort_order" :min="0" :max="9999" />
        <span class="hint small inline">數字小的在前</span>
      </el-form-item>
      <el-form-item label="狀態">
        <el-checkbox v-model="itemForm.is_active">上架</el-checkbox>
        <el-checkbox v-model="itemForm.is_sold_out">今日售完</el-checkbox>
      </el-form-item>
    </el-form>

    <!-- 圖片（品項存在之後才能上傳） -->
    <el-divider content-position="left">圖片</el-divider>
    <template v-if="itemEditing">
      <div class="images">
        <div v-for="(image, index) in currentImages" :key="image.id" class="image-cell">
          <el-image :src="image.thumb_url" fit="cover" class="image" :preview-src-list="currentImages.map((i) => i.url)" :initial-index="index" preview-teleported />
          <el-tag v-if="index === 0" size="small" type="success" class="cover-tag">封面</el-tag>
          <div v-if="canManage" class="image-ops">
            <el-button link size="small" :disabled="index === 0" @click="moveImage(index, -1)">◀</el-button>
            <el-button link size="small" :disabled="index === currentImages.length - 1" @click="moveImage(index, 1)">▶</el-button>
            <el-button link size="small" type="danger" @click="deleteImage(image.id)">刪除</el-button>
          </div>
        </div>
        <div v-if="canManage && remainingSlots > 0" class="image-cell add" @click="!uploading && fileInput?.click()">
          <span v-if="!uploading">＋ 上傳</span>
          <span v-else>上傳中…</span>
        </div>
      </div>
      <input ref="fileInput" type="file" multiple hidden :accept="MENU_IMAGE_LIMITS.accept" @change="onFilesSelected" />
      <p class="hint small">
        JPEG／PNG／WebP，單張 {{ MENU_IMAGE_LIMITS.maxMegabytes }}MB 以內，最多 {{ MENU_IMAGE_LIMITS.maxImages }} 張。第一張是封面，可用 ◀ ▶ 調整順序。
        系統會自動縮圖並移除照片裡的拍攝位置等資訊。
      </p>
    </template>
    <p v-else class="hint small">先儲存品項，才能上傳圖片。</p>

    <template #footer>
      <el-button @click="itemDialog = false">{{ itemEditing && itemEditing.id ? '關閉' : '取消' }}</el-button>
      <el-button type="primary" :loading="savingItem" @click="saveItem">儲存</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.hint {
  color: #606266;
  font-size: 14px;
  margin-top: 0;
}
.hint.small {
  font-size: 12px;
  color: #909399;
}
.hint.inline {
  margin: 0 0 0 12px;
}
.layout {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.side {
  width: 200px;
  flex-shrink: 0;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
}
.side-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  border-bottom: 1px solid #e4e7ed;
  font-weight: 600;
}
.cats {
  list-style: none;
  margin: 0;
  padding: 0;
}
.cats li {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  padding: 8px 12px;
  cursor: pointer;
}
.cats li:hover {
  background: #f5f7fa;
}
.cats li.active {
  background: #ecf5ff;
  color: #409eff;
}
.cat-name {
  flex: 1;
}
.tag {
  margin-left: 4px;
}
.count {
  margin-left: auto;
  color: #909399;
  font-size: 12px;
}
.ops {
  flex-basis: 100%;
}
.main {
  flex: 1;
  min-width: 0;
}
.toolbar {
  margin-bottom: 12px;
}
.thumb {
  width: 64px;
  height: 64px;
  border-radius: 4px;
  display: block;
}
.thumb.empty {
  background: #f5f7fa;
  color: #c0c4cc;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.name {
  font-weight: 600;
}
.desc {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}
.images {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.image-cell {
  position: relative;
  width: 120px;
}
.image {
  width: 120px;
  height: 90px;
  border-radius: 4px;
  display: block;
}
.cover-tag {
  position: absolute;
  top: 4px;
  left: 4px;
}
.image-ops {
  display: flex;
  justify-content: space-between;
}
.image-cell.add {
  height: 90px;
  border: 1px dashed #c0c4cc;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  cursor: pointer;
}
.image-cell.add:hover {
  border-color: #409eff;
  color: #409eff;
}
</style>
