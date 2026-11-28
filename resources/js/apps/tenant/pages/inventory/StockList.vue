<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeftRight, Boxes, ChartColumn, ClipboardCheck, ImageIcon, Lightbulb, List, PackagePlus, Plus, Printer, ShoppingBag, SlidersHorizontal, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import StockOverview from '../../components/StockOverview.vue';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadLink from '../products/ui/HeadLink.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import Pills from '../products/ui/Pills.vue';
import SearchField from '../products/ui/SearchField.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, th, thead } from '../products/ui/ui';
import Button from '@/shared/ui/Button.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Ref as Named, StockRow, StockSummary, Warehouse } from '@/shared/types';

/** "Quản lý kho" (like Sapo): one row per variant with stock and its prices; tabs by stock state. */
const tenant = useTenantStore();
const toast = useToast();
const canConfigure = computed(() => tenant.can('inventory.adjust.approve'));
const canCost = computed(() => tenant.can('products.cost.view'));

const warehouses = ref<Warehouse[]>([]);
const categories = ref<Named[]>([]);
const brands = ref<Named[]>([]);
const rows = ref<StockRow[]>([]);
const summary = ref<StockSummary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 50 });
provide('soft-cards', true);
const loading = ref(true);
const loadError = ref<string | null>(null);
const route = useRoute();
const initialState = typeof route.query.state === 'string' && ['in', 'low', 'out'].includes(route.query.state) ? route.query.state : 'all';
const filters = reactive({ warehouse: '', q: '', state: initialState, category: '', brand: '', page: 1 });
/** Wholesale prices are often unused: the column only shows when a row on this page has one. */
const showWholesale = computed(() => rows.value.some((r) => Number(r.wholesale_price ?? 0) > 0));

const activeWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active'));
const warehouseOptions = computed(() => [
    { value: '', label: 'Tất cả kho' },
    ...activeWarehouses.value.map((w) => ({ value: w.id, label: w.on_hand !== undefined ? `${w.name} (${formatNumber(w.on_hand)})` : w.name })),
]);
const stateOptions = computed(() => [
    { value: 'all', label: 'Tất cả' },
    { value: 'in', label: 'Còn hàng' },
    { value: 'low', label: summary.value ? `Sắp hết (${formatNumber(summary.value.low_stock)})` : 'Sắp hết' },
    { value: 'out', label: summary.value ? `Hết hàng (${formatNumber(summary.value.out_of_stock)})` : 'Hết hàng' },
]);

/** Right column: stock per warehouse, SKUs to reorder (at or below safety stock), latest stock documents. */
const lowStock = ref<{ variant_id: string; sku: string; name: string; warehouse: string; available: number; safety_stock: number }[]>([]);
const lowTotal = ref(0);
type RecentDoc = { kind: 'receipts' | 'adjustments' | 'transfers'; id: string; number: string; created_at: string; lines_count?: number; text: string };
const recent = ref<RecentDoc[]>([]);
async function loadSide() {
    const [low, ...docs] = await Promise.all([
        api.get('/inventory/low-stock').catch(() => null),
        ...(['receipts', 'adjustments', 'transfers'] as const).map((k) => api.get(`/inventory/${k}`).then((r) => ({ k, rows: r.data.data })).catch(() => ({ k, rows: [] }))),
    ]);
    lowStock.value = low?.data.data ?? [];
    lowTotal.value = low?.data.meta?.total ?? lowStock.value.length;
    type Doc = { id: string; number: string; created_at: string; lines_count?: number; warehouse?: { name: string } | null; from_warehouse?: { name: string } | null; to_warehouse?: { name: string } | null; reason?: string | null };
    const label = (k: RecentDoc['kind'], d: Doc) =>
        k === 'transfers' ? `${d.from_warehouse?.name ?? ''} → ${d.to_warehouse?.name ?? ''}` : `${k === 'receipts' ? 'Nhập kho' : 'Điều chỉnh'} · ${d.warehouse?.name ?? ''}`;
    recent.value = (docs as { k: RecentDoc['kind']; rows: Doc[] }[])
        .flatMap(({ k, rows }) => rows.slice(0, 3).map((d) => ({ kind: k, id: d.id, number: d.number, created_at: d.created_at, lines_count: d.lines_count, text: label(k, d) })))
        .sort((a, b) => b.created_at.localeCompare(a.created_at))
        .slice(0, 4);
}
const docIcon = { receipts: [PackagePlus, 'bg-emerald-50 text-emerald-600'], adjustments: [SlidersHorizontal, 'bg-amber-50 text-amber-600'], transfers: [ArrowLeftRight, 'bg-blue-50 text-blue-600'] } as const;
const level = (row: StockRow) => Math.min(100, Math.round((Math.max(row.available, 0) / Math.max(row.safety_stock * 3, 10)) * 100));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const params = {
            warehouse: filters.warehouse || undefined,
            q: filters.q || undefined,
            state: filters.state === 'all' ? undefined : filters.state,
            category: filters.category || undefined,
            brand: filters.brand || undefined,
            page: filters.page,
        };
        const [stock, sum] = await Promise.all([api.get('/inventory/stock', { params }), api.get('/inventory/summary', { params: { warehouse: params.warehouse } })]);
        rows.value = stock.data.data;
        meta.value = stock.data.meta;
        summary.value = sum.data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    // Categories and brands need products.view; a warehouse-only member simply gets no such filters.
    const [w, c, b] = await Promise.all([api.get('/warehouses'), api.get('/categories').catch(() => null), api.get('/brands').catch(() => null)]);
    warehouses.value = w.data.data;
    categories.value = c?.data.data ?? [];
    brands.value = b?.data.data ?? [];
    await Promise.all([load(), loadSide()]);
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 250);
});
watch(() => [filters.warehouse, filters.state, filters.category, filters.brand], () => ((filters.page = 1), load()));

const editing = ref<string | null>(null);
const safetyValue = ref('');
function startEdit(row: StockRow) {
    if (!canConfigure.value || !filters.warehouse) return;
    editing.value = row.variant.id;
    safetyValue.value = String(row.safety_stock);
}
async function saveSafety(row: StockRow) {
    const value = Number(safetyValue.value);
    editing.value = null;
    if (!Number.isInteger(value) || value < 0 || value === row.safety_stock) return;
    try {
        await api.put(`/inventory/variants/${row.variant.id}/safety-stock`, { warehouse: filters.warehouse, safety_stock: value });
        row.safety_stock = value;
        row.sellable = Math.max(0, row.available - value);
        toast.success(`Đã đặt tồn an toàn ${row.variant.sku} = ${value}`);
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

const isLow = (row: StockRow) => row.available > 0 && row.available <= row.safety_stock;
</script>

<template>
    <ProductsHead title="Quản lý kho" description="Tồn từng phiên bản theo kho: đang có, đang giữ cho đơn, có thể bán và tồn an toàn.">
        <template #links>
            <HeadLink :icon="WarehouseIcon" to="/inventory/warehouses">Danh sách kho</HeadLink>
            <HeadLink :icon="PackagePlus" to="/inventory/receipts" data-testid="receipts-link">Phiếu nhập kho</HeadLink>
            <HeadLink :icon="Printer" to="/products/labels">In tem mã vạch</HeadLink>
            <HeadLink v-if="tenant.can('products.view')" :icon="List" to="/products">Danh sách sản phẩm</HeadLink>
            <HeadLink v-if="tenant.can('reports.view')" :icon="ChartColumn" to="/reports/group/stock">Báo cáo kho</HeadLink>
        </template>
        <HeadButton :icon="ClipboardCheck" to="/inventory/adjustments">Điều chỉnh tồn</HeadButton>
        <HeadButton :icon="ArrowLeftRight" to="/inventory/transfers">Chuyển hàng</HeadButton>
        <HeadButton v-if="tenant.can('purchasing.manage')" :icon="Plus" to="/purchasing/new" primary data-testid="new-purchase">Nhập hàng</HeadButton>
        <HeadButton v-else-if="tenant.can('inventory.receive')" :icon="Plus" to="/inventory/receipts/new" primary data-testid="new-receipt">Nhập kho</HeadButton>
    </ProductsHead>

    <StockOverview :summary="summary" :active="filters.state" @select="(s) => (filters.state = s)" />

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_360px]">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3.5">
                <Pills v-if="activeWarehouses.length > 1 && activeWarehouses.length <= 4" v-model="filters.warehouse" :options="warehouseOptions" label="Kho" data-testid="stock-warehouse" />
                <Select v-else v-model="filters.warehouse" :options="warehouseOptions" class="w-full sm:w-56" data-testid="stock-warehouse" aria-label="Kho" />
                <Pills v-model="filters.state" :options="stateOptions" label="Tình trạng tồn" class="xl:ml-auto" data-testid="stock-tabs" />
            </div>
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                <SearchField v-model="filters.q" placeholder="Tìm theo tên, SKU, mã vạch" data-testid="stock-search" />
                <Select v-if="categories.length" v-model="filters.category" class="w-full sm:w-44" aria-label="Danh mục" :options="[{ value: '', label: 'Danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" />
                <Select v-if="brands.length" v-model="filters.brand" class="w-full sm:w-44" aria-label="Thương hiệu" :options="[{ value: '', label: 'Thương hiệu' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
            </div>

            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState
                v-else-if="!rows.length"
                :icon="Boxes"
                :title="filters.q || filters.state !== 'all' || filters.category || filters.brand ? 'Không có phiên bản phù hợp' : 'Chưa có sản phẩm'"
                description="Tạo sản phẩm hoặc nhập từ Excel, sau đó nhập hàng để bắt đầu theo dõi tồn."
            >
                <RouterLink to="/products"><Button variant="outline">Đi tới Danh sách sản phẩm</Button></RouterLink>
            </EmptyState>
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full min-w-[960px]" data-testid="stock-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Phiên bản sản phẩm</th>
                            <th :class="cn(th, 'text-right')">Tồn kho</th>
                            <th :class="cn(th, 'text-right')">Đang giữ</th>
                            <th :class="th" title="Tồn kho trừ số đang giữ cho đơn">Có thể bán</th>
                            <th :class="cn(th, 'text-right')" :title="filters.warehouse ? 'Bấm để sửa' : 'Chọn một kho để sửa'">Tồn an toàn</th>
                            <th :class="cn(th, 'text-right')">Giá bán lẻ</th>
                            <th v-if="canCost" :class="cn(th, 'text-right')">Giá vốn</th>
                            <th v-if="showWholesale" :class="cn(th, 'text-right')">Giá bán buôn</th>
                            <th :class="th">Tình trạng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.variant.id" class="border-t border-slate-100 hover:bg-slate-50/70">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img v-if="row.product.image" :src="row.product.image" alt="" class="size-10 shrink-0 rounded-[10px] border border-slate-200 object-cover" />
                                    <span v-else class="grid size-10 shrink-0 place-items-center rounded-[10px] border border-dashed border-slate-300 bg-slate-50 text-slate-400"><ImageIcon class="size-4" /></span>
                                    <div class="min-w-0">
                                        <RouterLink :to="`/inventory/sku/${row.variant.id}`" class="text-[14px] font-medium text-slate-800 hover:text-blue-700 hover:underline">
                                            {{ row.product.name }}<template v-if="row.variant.name"> – {{ row.variant.name }}</template>
                                        </RouterLink>
                                        <p class="font-mono text-[12px] text-slate-500">{{ row.variant.sku }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right text-[14px] tabular-nums">{{ formatNumber(row.on_hand) }}</td>
                            <td class="px-4 py-3 text-right text-[14px] tabular-nums text-slate-500">{{ row.reserved ? formatNumber(row.reserved) : '—' }}</td>
                            <td class="w-[170px] px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <b :class="cn('w-10 text-right text-[15px] tabular-nums', row.available <= 0 ? 'text-red-600' : isLow(row) ? 'text-amber-600' : 'text-slate-900')">{{ formatNumber(row.available) }}</b>
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                        <div :class="cn('h-full rounded-full', row.available <= 0 ? 'bg-red-500' : isLow(row) ? 'bg-amber-500' : 'bg-emerald-500')" :style="{ width: `${level(row)}%` }" />
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <input
                                    v-if="editing === row.variant.id"
                                    v-model="safetyValue"
                                    type="number"
                                    min="0"
                                    class="h-8 w-20 rounded-[8px] border border-blue-400 bg-white px-2 text-right text-[13px] outline-none ring-2 ring-blue-100"
                                    autofocus
                                    @keydown.enter="saveSafety(row)"
                                    @keydown.esc="editing = null"
                                    @blur="saveSafety(row)"
                                />
                                <button
                                    v-else
                                    type="button"
                                    :class="cn('inline-flex min-w-12 items-center justify-end gap-1.5 rounded-[8px] px-2.5 py-1 text-[13px] tabular-nums text-slate-700', canConfigure && filters.warehouse && 'border border-dashed border-slate-300 hover:border-blue-400')"
                                    @click="startEdit(row)"
                                >
                                    {{ row.safety_stock || '—' }}
                                </button>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-[14px] tabular-nums">{{ formatMoney(row.ref_price ?? 0) }}</td>
                            <td v-if="canCost" class="whitespace-nowrap px-4 py-3 text-right text-[14px] tabular-nums text-slate-600">{{ formatMoney(row.cost_price) }}</td>
                            <td v-if="showWholesale" class="whitespace-nowrap px-4 py-3 text-right text-[14px] tabular-nums text-slate-600">{{ formatMoney(row.wholesale_price ?? 0) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <StatusChip v-if="row.available <= 0" tone="red">Hết hàng</StatusChip>
                                <StatusChip v-else-if="isLow(row)" tone="amber">Sắp hết</StatusChip>
                                <StatusChip v-else tone="green">Còn hàng</StatusChip>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page ?? 50" :count="rows.length" unit="phiên bản" @go="(p) => ((filters.page = p), load())" />
        </section>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard title="Theo kho" :description="`${activeWarehouses.length} kho đang dùng`">
                <ul class="divide-y divide-slate-100">
                    <li v-for="w in activeWarehouses" :key="w.id">
                        <button type="button" :class="cn('flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-slate-50', filters.warehouse === w.id && 'bg-blue-50/60')" @click="filters.warehouse = filters.warehouse === w.id ? '' : w.id">
                            <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', w.purpose === 'defective' ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600')"><WarehouseIcon class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-slate-800">{{ w.name }}</span>
                                <span class="block text-[12px] text-slate-500">{{ [w.is_default ? 'Mặc định' : null, w.purpose === 'defective' ? 'Hàng lỗi' : null, w.code].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <span class="text-right">
                                <span v-if="canCost && w.stock_value != null" class="block text-[14px] font-semibold tabular-nums">{{ formatNumber(Number(w.stock_value)) }}</span>
                                <span class="block text-[12px] text-slate-500">{{ formatNumber(w.on_hand ?? 0) }} sp</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </SideCard>
            <SideCard v-if="lowStock.length" title="Nên nhập thêm" description="Có thể bán không quá tồn an toàn">
                <ul class="divide-y divide-slate-100 py-1">
                    <li v-for="l in lowStock.slice(0, 5)" :key="l.variant_id + l.warehouse" class="flex items-center gap-3 px-5 py-2.5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><Lightbulb class="size-4" /></span>
                        <RouterLink :to="`/inventory/sku/${l.variant_id}`" class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium text-slate-800 hover:text-blue-700">{{ l.name }}</span>
                            <span class="block text-[12px] text-slate-500">Còn {{ formatNumber(l.available) }} · an toàn {{ formatNumber(l.safety_stock) }} · {{ l.warehouse }}</span>
                        </RouterLink>
                        <StatusChip :tone="l.available <= 0 ? 'red' : 'amber'">Thiếu {{ formatNumber(Math.max(1, l.safety_stock - l.available)) }}</StatusChip>
                    </li>
                </ul>
                <div v-if="tenant.can('purchasing.view')" class="border-t border-slate-100 px-5 py-3">
                    <RouterLink to="/purchasing/reorder" class="flex h-10 items-center justify-center gap-2 rounded-[10px] bg-blue-600 text-[14px] font-medium text-white hover:bg-blue-700"><ShoppingBag class="size-4" /> Mở Gợi ý nhập hàng ({{ formatNumber(lowTotal) }})</RouterLink>
                </div>
            </SideCard>
            <SideCard v-if="recent.length" title="Chứng từ kho gần đây" description="Nhập kho, điều chỉnh, chuyển hàng">
                <ul class="divide-y divide-slate-100 text-[13px]">
                    <li v-for="d in recent" :key="d.kind + d.id">
                        <RouterLink :to="`/inventory/${d.kind}/${d.id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50">
                            <span :class="cn('grid size-8 shrink-0 place-items-center rounded-[9px]', docIcon[d.kind][1])"><component :is="docIcon[d.kind][0]" class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-mono font-semibold text-slate-800">{{ d.number }}</span>
                                <span class="block truncate text-slate-500">{{ d.text }}<template v-if="d.lines_count"> · {{ d.lines_count }} SKU</template></span>
                            </span>
                            <span class="whitespace-nowrap text-slate-500">{{ formatDate(d.created_at) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </SideCard>
        </aside>
    </div>
</template>
