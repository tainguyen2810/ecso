<script setup lang="ts">
import { computed } from 'vue';
import { Boxes, Package, PackageCheck, PackageX, TriangleAlert, Wallet } from 'lucide-vue-next';
import ReportKpi from '../pages/reports/ReportKpi.vue';
import ReportChip from '../pages/reports/ReportChip.vue';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { StockSummary } from '@/shared/types';

/**
 * Stock at a glance above the product and stock lists (mẫu Tailwind 28/11): a blue lead card, then in stock / running
 * low / out of stock. Those three pick that stock state in the list below. When most variants have no stock, a hint
 * explains that the stock was probably never entered (orders then all say "Thiếu hàng") and links to an adjustment.
 */
const props = withDefaults(defineProps<{ summary: StockSummary | null; active?: string; mode?: 'products' | 'stock'; activeProducts?: number | null }>(), {
    mode: 'stock',
    active: 'all',
    activeProducts: null,
});
const emit = defineEmits<{ select: [state: string] }>();
const tenant = useTenantStore();
const canCost = computed(() => tenant.can('products.cost.view') && props.summary?.inventory_value != null);

const s = computed(() => props.summary);
const inStock = computed(() => s.value?.in_stock ?? Math.max(0, (s.value?.skus ?? 0) - (s.value?.out_of_stock ?? 0)));
const pct = computed(() => (s.value?.skus ? `${((inStock.value / s.value.skus) * 100).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}% phiên bản` : '0 phiên bản'));
const neverEntered = computed(() => !!s.value && s.value.skus >= 10 && s.value.out_of_stock / s.value.skus >= 0.3);
const pick = (state: string) => emit('select', props.active === state ? 'all' : state);
const ring = (state: string) => cn('block w-full rounded-[14px] text-left transition hover:-translate-y-px', props.active === state && 'ring-2 ring-blue-500/60 ring-offset-2 ring-offset-[#f5f7fb]');
</script>

<template>
    <div class="mb-5 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="stock-overview">
            <ReportKpi v-if="mode === 'products'" label="Sản phẩm đang bán" :icon="Package" primary :value="activeProducts" unit="" tip="Sản phẩm ở trạng thái Đang bán, không tính combo đã lưu trữ.">
                <template #chips><ReportChip tone="onblue">{{ formatNumber(s?.skus ?? 0) }} phiên bản</ReportChip></template>
                <template v-if="canCost">Giá trị tồn theo giá vốn {{ formatMoney(s!.inventory_value) }}</template>
                <template v-else>Tồn {{ formatNumber(s?.on_hand ?? 0) }} sản phẩm ở tất cả các kho</template>
            </ReportKpi>
            <ReportKpi v-else-if="canCost" label="Giá trị tồn kho" :icon="Wallet" primary :value="Math.round(Number(s!.inventory_value))" tip="Số đang có ở các kho nhân giá vốn bình quân của từng phiên bản.">
                <template #chips><ReportChip tone="onblue">Theo giá vốn</ReportChip></template>
                {{ formatNumber(s?.on_hand ?? 0) }} sản phẩm đang có trong kho
            </ReportKpi>
            <ReportKpi v-else label="Tổng tồn" :icon="Boxes" primary :value="s?.on_hand ?? null" unit="">
                <template #chips><ReportChip tone="onblue">{{ formatNumber(s?.skus ?? 0) }} phiên bản</ReportChip></template>
                {{ formatNumber(s?.reserved ?? 0) }} đang giữ cho đơn chưa giao
            </ReportKpi>

            <button v-if="mode === 'products' || !canCost" type="button" :class="ring('in')" data-testid="stock-card-in" @click="pick('in')">
                <ReportKpi label="Còn hàng" :icon="PackageCheck" tone="green" plain :value="s ? inStock : null" unit="">
                    <template #chips><ReportChip tone="up">{{ pct }}</ReportChip></template>
                    Bấm để lọc danh sách bên dưới
                </ReportKpi>
            </button>
            <ReportKpi v-else label="Tổng tồn" :icon="Boxes" tone="green" plain :value="s?.on_hand ?? null" unit="">
                <template #chips><ReportChip tone="up">{{ formatNumber(inStock) }} phiên bản có tồn</ReportChip></template>
                {{ formatNumber(s?.reserved ?? 0) }} đang giữ cho đơn chưa giao
            </ReportKpi>

            <button type="button" :class="ring('low')" data-testid="stock-card-low" @click="pick('low')">
                <ReportKpi label="Sắp hết hàng" :icon="TriangleAlert" tone="amber" :value="s?.low_stock ?? null" unit="" tip="Có thể bán còn lớn hơn 0 nhưng không quá tồn an toàn.">
                    <template #chips><ReportChip tone="amber">≤ tồn an toàn</ReportChip></template>
                    {{ mode === 'stock' ? 'Gợi ý nhập hàng đã chọn sẵn số lượng' : 'Nên nhập thêm sớm' }}
                </ReportKpi>
            </button>
            <button type="button" :class="ring('out')" data-testid="stock-card-out" @click="pick('out')">
                <ReportKpi label="Hết hàng" :icon="PackageX" tone="red" :value="s?.out_of_stock ?? null" unit="" tip="Có thể bán bằng 0 ở tất cả các kho (tồn trừ số đang giữ cho đơn).">
                    <template #chips><ReportChip tone="red">Có thể bán = 0</ReportChip></template>
                    Ở tất cả các kho
                </ReportKpi>
            </button>
        </div>
        <div v-if="neverEntered" class="flex flex-wrap items-center gap-3 rounded-[14px] border border-amber-200 bg-amber-50 px-5 py-3.5 text-[14px] text-amber-800" data-testid="stock-setup-hint">
            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-100 text-amber-600"><PackageX class="size-[18px]" /></span>
            <span class="min-w-60 flex-1">
                <b>{{ formatNumber(summary!.out_of_stock) }} / {{ formatNumber(summary!.skus) }} phiên bản đang có tồn 0.</b>
                Nếu kho thật vẫn còn hàng, hãy nhập số hàng đang có một lần, đơn mới sẽ không còn báo "Thiếu hàng".
            </span>
            <RouterLink v-if="tenant.can('inventory.adjust')" to="/inventory/adjustments/new" class="inline-flex h-9 items-center rounded-[10px] bg-amber-500 px-4 font-medium text-white hover:bg-amber-600">Nhập số tồn đang có</RouterLink>
        </div>
    </div>
</template>
