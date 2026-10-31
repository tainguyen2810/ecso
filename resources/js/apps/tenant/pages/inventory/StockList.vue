<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { AlertTriangle, ArrowLeftRight, Boxes, ClipboardCheck, Coins, PackageCheck, PackagePlus, PackageX, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import InventoryNav from './InventoryNav.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { StockRow, StockSummary, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canConfigure = computed(() => tenant.can('inventory.adjust.approve'));

const warehouses = ref<Warehouse[]>([]);
const rows = ref<StockRow[]>([]);
const summary = ref<StockSummary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ warehouse: '', q: '', state: 'all', page: 1 });

const warehouseOptions = computed(() => [
    { value: '', label: 'Tất cả kho' },
    ...warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })),
]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const params = { warehouse: filters.warehouse || undefined, q: filters.q || undefined, state: filters.state === 'all' ? undefined : filters.state, page: filters.page };
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
    warehouses.value = (await api.get('/warehouses')).data.data;
    await load();
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 250);
});
watch(() => [filters.warehouse, filters.state], () => ((filters.page = 1), load()));

const cards = computed(() => [
    { label: 'SKU đang quản lý', value: formatNumber(summary.value?.skus), icon: Boxes, tone: '' },
    { label: 'Tổng tồn thực tế', value: formatNumber(summary.value?.on_hand), hint: `${formatNumber(summary.value?.reserved)} đang giữ cho đơn`, icon: PackageCheck, tone: '' },
    { label: 'Giá trị tồn (giá vốn)', value: formatMoney(summary.value?.inventory_value), icon: Coins, tone: '' },
    { label: 'Sắp hết hàng', value: formatNumber(summary.value?.low_stock), icon: AlertTriangle, tone: 'text-[oklch(0.55_0.13_70)]', state: 'low' },
    { label: 'Hết hàng', value: formatNumber(summary.value?.out_of_stock), icon: PackageX, tone: 'text-destructive', state: 'out' },
]);

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

function stateOf(row: StockRow): { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' } {
    if (row.available < 0) return { label: 'Thiếu hàng', tone: 'danger' };
    if (row.available === 0) return { label: 'Hết hàng', tone: 'danger' };
    if (row.available <= row.safety_stock) return { label: 'Sắp hết', tone: 'warning' };
    return { label: 'Còn hàng', tone: 'success' };
}
</script>

<template>
    <PageHeader title="Kho hàng" description="Tồn thực tế, đang giữ cho đơn và khả dụng theo từng SKU, từng kho.">
        <RouterLink v-if="tenant.can('inventory.transfer')" to="/inventory/transfers/new"><Button variant="outline"><ArrowLeftRight /> Chuyển kho</Button></RouterLink>
        <RouterLink v-if="tenant.can('inventory.adjust')" to="/inventory/adjustments/new"><Button variant="outline"><ClipboardCheck /> Điều chỉnh</Button></RouterLink>
        <RouterLink v-if="tenant.can('inventory.receive')" to="/inventory/receipts/new"><Button data-testid="new-receipt"><PackagePlus /> Nhập kho</Button></RouterLink>
    </PageHeader>
    <InventoryNav />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <Card
            v-for="c in cards"
            :key="c.label"
            :class="cn('p-4', c.state && 'cursor-pointer hover:border-primary/40', c.state && filters.state === c.state && 'border-primary ring-1 ring-primary/30')"
            @click="c.state && (filters.state = filters.state === c.state ? 'all' : c.state)"
        >
            <div class="flex items-center justify-between text-xs text-muted-foreground">
                {{ c.label }} <component :is="c.icon" :class="cn('size-4', c.tone)" />
            </div>
            <p :class="cn('mt-2 text-xl font-semibold tabular-nums tracking-tight', c.tone)">
                <Skeleton v-if="!summary" class="h-7 w-20" />
                <template v-else>{{ c.value }}</template>
            </p>
            <p v-if="c.hint && summary" class="mt-0.5 text-xs text-muted-foreground">{{ c.hint }}</p>
        </Card>
    </div>

    <Card class="mt-5">
        <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <div class="relative w-full sm:w-72">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" data-testid="stock-search" placeholder="Tìm SKU, mã vạch, tên sản phẩm" class="pl-8" />
            </div>
            <Select v-model="filters.warehouse" :options="warehouseOptions" class="w-full sm:w-56" data-testid="stock-warehouse" />
            <Tabs
                v-model="filters.state"
                class="ml-auto"
                :tabs="[
                    { value: 'all', label: 'Tất cả' },
                    { value: 'in', label: 'Còn hàng' },
                    { value: 'low', label: 'Sắp hết' },
                    { value: 'out', label: 'Hết hàng' },
                ]"
            />
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="Boxes"
            :title="filters.q || filters.state !== 'all' ? 'Không có SKU phù hợp' : 'Chưa có sản phẩm'"
            description="Tạo sản phẩm hoặc import từ Excel, sau đó nhập kho để bắt đầu theo dõi tồn."
        >
            <RouterLink to="/products"><Button variant="outline">Đi tới Sản phẩm</Button></RouterLink>
        </EmptyState>
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="stock-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">SKU / Sản phẩm</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tồn thực tế</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đang giữ</th>
                        <th class="px-4 py-2.5 text-right font-medium">Khả dụng</th>
                        <th class="px-4 py-2.5 text-right font-medium" :title="filters.warehouse ? 'Bấm để sửa' : 'Chọn một kho để sửa'">Tồn an toàn</th>
                        <th class="px-4 py-2.5 text-right font-medium" title="Khả dụng − tồn an toàn: số có thể đẩy lên sàn">Có thể bán</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá vốn BQ</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in rows" :key="row.variant.id" class="hover:bg-muted/30">
                        <td class="px-4 py-2.5">
                            <RouterLink :to="`/inventory/sku/${row.variant.id}`" class="font-mono text-xs font-medium text-primary hover:underline">{{ row.variant.sku }}</RouterLink>
                            <p class="max-w-xs truncate text-muted-foreground">{{ row.product.name }}<template v-if="row.variant.name"> · {{ row.variant.name }}</template></p>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(row.on_hand) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ row.reserved ? formatNumber(row.reserved) : '—' }}</td>
                        <td :class="cn('px-4 py-2.5 text-right font-semibold tabular-nums', row.available <= 0 && 'text-destructive')">{{ formatNumber(row.available) }}</td>
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
                            <button v-else :class="cn('rounded px-1.5 py-0.5', canConfigure && filters.warehouse && 'hover:bg-muted')" @click="startEdit(row)">{{ row.safety_stock || '—' }}</button>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(row.sellable) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatMoney(row.cost_price) }}</td>
                        <td class="px-4 py-2.5"><Badge :tone="stateOf(row).tone">{{ stateOf(row).label }}</Badge></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>
</template>
