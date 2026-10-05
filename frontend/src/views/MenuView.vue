<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/http'
import BookingSteps from '@/components/BookingSteps.vue'
import { useBookingStore } from '@/stores/booking'
import type { MenuCategoryGroup, MenuItem } from '@/types/api'

const booking = useBookingStore()
const router = useRouter()

const groups = ref<MenuCategoryGroup[]>([])
const loading = ref(false)
const activeKey = ref<string>('')

const allItems = computed(() => groups.value.flatMap((g) => g.items))
const totalPieces = computed(() => booking.cart.reduce((sum, line) => sum + line.quantity, 0))
const totalPrice = computed(() => booking.cart.reduce((sum, line) => sum + line.price * line.quantity, 0))

const groupKey = (g: MenuCategoryGroup): string => String(g.id ?? 'other')

/** 分類頁籤：點了只顯示該分類；全部分類太多時單一分類的畫面比較好讀。 */
const visibleGroups = computed(() => groups.value.filter((g) => groupKey(g) === activeKey.value))

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get<{ categories: MenuCategoryGroup[] }>(`/stores/${booking.storeId}/menu`)
    groups.value = data.categories
    activeKey.value = data.categories[0] ? groupKey(data.categories[0]) : ''

    // 之前選的餐點若已被門市下架或售完，從購物車移除，避免送出時整筆被拒絕
    const orderable = new Set(allItems.value.filter((item) => !item.is_sold_out).map((item) => item.id))
    for (const line of [...booking.cart]) {
      if (!orderable.has(line.id)) {
        booking.setQuantity(line, 0)
      }
    }
  } catch (error) {
    ElMessage.error(errorMessage(error, '載入菜單失敗'))
  } finally {
    loading.value = false
  }
}

// ── 品項詳情（多張大圖與完整說明）──────────────────────────────────────
const detail = ref<MenuItem | null>(null)
const detailVisible = computed({
  get: () => detail.value !== null,
  set: (open: boolean) => {
    if (!open) {
      detail.value = null
    }
  },
})

function setQuantity(item: MenuItem, quantity: number | undefined): void {
  booking.setQuantity(item, quantity ?? 0)
}

onMounted(load)
</script>

<template>
  <BookingSteps :active="3" />

  <h2>預點餐點（選填）</h2>
  <p class="note">預點只是讓門市提早備餐，<strong>不需線上付款</strong>，用餐時再結帳。不想預點可直接按下一步。</p>

  <div v-loading="loading" class="wrap">
    <el-empty v-if="!loading && groups.length === 0" description="這間門市目前沒有提供預點" />

    <template v-else>
      <el-tabs v-model="activeKey" class="tabs">
        <el-tab-pane v-for="g in groups" :key="groupKey(g)" :label="g.name" :name="groupKey(g)" />
      </el-tabs>

      <section v-for="g in visibleGroups" :key="groupKey(g)" class="list">
        <el-card v-for="item in g.items" :key="item.id" shadow="never" class="item" :class="{ soldout: item.is_sold_out }">
          <div class="row">
            <div class="photo" @click="detail = item">
              <img v-if="item.images.length" :src="item.images[0]!.thumb_url" :alt="item.name" loading="lazy" />
              <div v-else class="noimg">無圖</div>
              <span v-if="item.images.length > 1" class="more">{{ item.images.length }} 張</span>
            </div>

            <div class="info" @click="detail = item">
              <div class="name">{{ item.name }}</div>
              <div v-if="item.description" class="desc">{{ item.description }}</div>
              <div class="price">${{ item.price }}</div>
            </div>

            <div class="qty">
              <el-tag v-if="item.is_sold_out" type="info">今日售完</el-tag>
              <el-input-number
                v-else
                :model-value="booking.quantityOf(item.id)"
                :min="0"
                :max="20"
                size="small"
                @update:model-value="(v: number | undefined) => setQuantity(item, v)"
              />
            </div>
          </div>
        </el-card>
      </section>
    </template>
  </div>

  <p v-if="totalPieces > 0" class="summary">已選 {{ totalPieces }} 份，約 ${{ totalPrice }}（現場結帳）</p>

  <div class="actions">
    <el-button @click="router.push({ name: 'slot' })">上一步</el-button>
    <el-button type="primary" @click="router.push({ name: 'info' })">
      {{ totalPieces > 0 ? '下一步' : '略過' }}
    </el-button>
  </div>

  <!-- 詳情：多張大圖輪播 + 完整說明 -->
  <el-dialog v-model="detailVisible" :title="detail?.name" width="min(560px, 92vw)" align-center>
    <template v-if="detail">
      <el-carousel v-if="detail.images.length" :autoplay="false" height="300px" :arrow="detail.images.length > 1 ? 'always' : 'never'" :indicator-position="detail.images.length > 1 ? '' : 'none'">
        <el-carousel-item v-for="image in detail.images" :key="image.url">
          <img :src="image.url" :alt="detail.name" class="big" />
        </el-carousel-item>
      </el-carousel>
      <p v-if="detail.description" class="detail-desc">{{ detail.description }}</p>
      <div class="detail-foot">
        <span class="price">${{ detail.price }}</span>
        <el-tag v-if="detail.is_sold_out" type="info">今日售完</el-tag>
        <el-input-number
          v-else
          :model-value="booking.quantityOf(detail.id)"
          :min="0"
          :max="20"
          size="small"
          @update:model-value="(v: number | undefined) => setQuantity(detail!, v)"
        />
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.note {
  color: #606266;
  font-size: 14px;
}
.wrap {
  min-height: 80px;
}
.list {
  display: grid;
  gap: 10px;
}
.row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.photo {
  position: relative;
  width: 84px;
  height: 84px;
  flex-shrink: 0;
  border-radius: 6px;
  overflow: hidden;
  background: #f5f7fa;
  cursor: pointer;
}
.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.noimg {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
.more {
  position: absolute;
  right: 4px;
  bottom: 4px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 8px;
}
.info {
  flex: 1;
  min-width: 0;
  cursor: pointer;
}
.name {
  font-weight: 600;
}
.desc {
  color: #909399;
  font-size: 13px;
  margin-top: 2px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.price {
  color: #e6a23c;
  font-weight: 600;
  margin-top: 4px;
}
.item.soldout .photo,
.item.soldout .info {
  opacity: 0.55;
}
.qty {
  flex-shrink: 0;
}
.summary {
  margin-top: 16px;
  font-weight: 600;
}
.actions {
  display: flex;
  justify-content: space-between;
  margin-top: 24px;
}
.big {
  width: 100%;
  height: 100%;
  object-fit: contain;
  background: #f5f7fa;
}
.detail-desc {
  color: #606266;
  white-space: pre-line;
}
.detail-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
}
</style>
