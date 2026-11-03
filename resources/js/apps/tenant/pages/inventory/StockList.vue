<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ArrowLeftRight, Boxes, ClipboardCheck, ImageIcon, List, PackagePlus, Plus, Printer, Search, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import HeaderLink from '../../components/HeaderLink.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
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

const warehouses = ref<Warehouse[]>([]);
const categories = ref<Named[]>([]);
const brands = ref<Named[]>([]);
const rows = ref<StockRow[]>([]);
const summary = ref<StockSummary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ warehouse: '', q: '', state: 'all', category: '', brand: '', page: 1 });

const warehouseOptions = computed(() => [
    { value: '', label: 'Kho: Tất cả' },
    ...warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })),
]);
const tabs = computed(() => [
    { value: 'all', label: 'Tất cả phiên bản', count: summary.value?.skus ?? null },
    { value: 'in', label: 'Còn hàng' },
    { value: 'low', label: 'Sắp hết hàng', count: summary.value?.low_stock ?? null },
    { value: 'out', label: 'Hết hàng', count: summary.value?.out_of_stock ?? null },
]);

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
    await load();
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
    <PageHeader title="Quản lý kho">
        <template #links>
            <HeaderLink :icon="Printer" to="/products/labels">In tem mã</HeaderLink>
            <HeaderLink :icon="PackagePlus" to="/inventory/receipts" data-testid="receipts-link">Nhập kho</HeaderLink>
            <HeaderLink :icon="ClipboardCheck" to="/inventory/adjustments">Điều chỉnh tồn</HeaderLink>
            <HeaderLink :icon="ArrowLeftRight" to="/inventory/transfers">Chuyển hàng</HeaderLink>
            <HeaderLink :icon="WarehouseIcon" to="/inventory/warehouses">Danh sách kho</HeaderLink>
            <HeaderLink v-if="tenant.can('products.view')" :icon="List" to="/products">Xem danh sách sản phẩm</HeaderLink>
        </template>
        <RouterLink v-if="tenant.can('purchasing.manage')" to="/purchasing/new"><Button data-testid="new-purchase"><Plus /> Nhập hàng</Button></RouterLink>
        <RouterLink v-else-if="tenant.can('inventory.receive')" to="/inventory/receipts/new"><Button data-testid="new-receipt"><Plus /> Nhập kho</Button></RouterLink>
    </PageHeader>

    <Card>
        <div class="border-b px-3"><Tabs v-model="filters.state" :tabs="tabs" variant="underline" data-testid="stock-tabs" /></div>
        <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <div class="relative w-full sm:min-w-64 sm:flex-1">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" data-testid="stock-search" placeholder="Tìm theo tên, SKU, mã vạch" class="pl-8" />
            </div>
            <Select v-model="filters.warehouse" :options="warehouseOptions" class="w-full sm:w-48" data-testid="stock-warehouse" aria-label="Kho" />
            <Select v-if="categories.length" v-model="filters.category" class="w-full sm:w-44" aria-label="Danh mục" :options="[{ value: '', label: 'Danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" />
            <Select v-if="brands.length" v-model="filters.brand" class="w-full sm:w-44" aria-label="Thương hiệu" :options="[{ value: '', label: 'Thương hiệu' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
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
            <table class="w-full text-sm" data-testid="stock-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-14 py-2.5 pl-4 font-medium">Ảnh</th>
                        <th class="px-4 py-2.5 font-medium">Tên phiên bản sản phẩm</th>
                        <th class="px-4 py-2.5 text-right font-medium" title="Tồn kho trừ số đang giữ cho đơn">Có thể bán</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tồn kho</th>
                        <th class="px-4 py-2.5 text-right font-medium" :title="filters.warehouse ? 'Bấm để sửa' : 'Chọn một kho để sửa'">Tồn an toàn</th>
                        <th class="px-4 py-2.5 font-medium">Ngày khởi tạo</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá bán lẻ</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá vốn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá bán buôn</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in rows" :key="row.variant.id" class="hover:bg-muted/30">
                        <td class="py-2.5 pl-4">
                            <img v-if="row.product.image" :src="row.product.image" alt="" class="size-10 rounded border object-cover" />
                            <span v-else class="flex size-10 items-center justify-center rounded border bg-muted/50 text-muted-foreground"><ImageIcon class="size-4" /></span>
                        </td>
                        <td class="px-4 py-2.5">
                            <RouterLink :to="`/inventory/sku/${row.variant.id}`" class="font-medium text-primary hover:underline">
                                {{ row.product.name }}<template v-if="row.variant.name"> ({{ row.variant.name }})</template>
                            </RouterLink>
                            <p class="font-mono text-xs text-muted-foreground">{{ row.variant.sku }}</p>
                        </td>
                        <td
                            :class="cn('px-4 py-2.5 text-right font-semibold tabular-nums', row.available <= 0 ? 'text-destructive' : isLow(row) && 'text-amber-600')"
                            :title="row.reserved ? `${formatNumber(row.reserved)} đang giữ cho đơn` : undefined"
                        >
                            {{ formatNumber(row.available) }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(row.on_hand) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            <input
                                v-if="editing === row.variant.id"
                                v-model="safetyValue"
                                type="number"
                                min="0"
                                class="h-7 w-20 rounded border border-input bg-card px-2 text-right text-sm"
                                autofocus
                                @keydown.enter="saveSafety(row)"
                                @keydown.esc="editing = null"
                                @blur="saveSafety(row)"
                            />
                            <button v-else :class="cn('rounded px-1.5 py-0.5 text-muted-foreground', canConfigure && filters.warehouse && 'hover:bg-muted')" @click="startEdit(row)">{{ row.safety_stock || '—' }}</button>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ row.created_at ? formatDate(row.created_at) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(row.ref_price ?? 0) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(row.cost_price) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(row.wholesale_price ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>
</template>
