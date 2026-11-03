<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ClipboardList, Lightbulb, Plus, Search, Truck, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import HeaderLink from '../../components/HeaderLink.vue';
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
import { formatDate, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { owed, poStatus, receiveStatus, tradeStatus } from './labels';
import type { PurchaseOrderData, Supplier, Warehouse } from '@/shared/types';

/**
 * Two lists over the same purchase orders, like Sapo: "Nhập hàng" (every order, tabs by the deal) and
 * "Đặt hàng nhập" (orders placed with the supplier where nothing has arrived yet).
 */
const tenant = useTenantStore();
const route = useRoute();
const ordering = computed(() => route.meta.mode === 'ordering');

const rows = ref<PurchaseOrderData[]>([]);
const suppliers = ref<Supplier[]>([]);
const warehouses = ref<Warehouse[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ q: '', tab: String(route.query.status ?? ''), supplier: '', warehouse: '', created_from: '', created_to: '', page: 1 });

const tabs = computed(() =>
    ordering.value
        ? [
              { value: '', label: 'Tất cả đơn đặt' },
              { value: 'draft', label: 'Nháp' },
              { value: 'ordered', label: 'Đã đặt hàng' },
          ]
        : [
              { value: '', label: 'Tất cả phiếu nhập' },
              { value: 'trading', label: 'Đang giao dịch' },
              { value: 'done', label: 'Hoàn thành' },
              { value: 'unpaid', label: 'Còn nợ NCC' },
              { value: 'cancelled', label: 'Đã hủy' },
          ],
);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/purchase-orders', {
            params: {
                q: filters.q || undefined,
                status: filters.tab || (ordering.value ? 'ordering' : undefined),
                supplier: filters.supplier || undefined,
                warehouse: filters.warehouse || undefined,
                created_from: filters.created_from || undefined,
                created_to: filters.created_to || undefined,
                page: filters.page,
            },
        });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    const [s, w] = await Promise.all([api.get('/suppliers', { params: { per_page: 200 } }), api.get('/warehouses')]);
    suppliers.value = s.data.data;
    warehouses.value = w.data.data;
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(() => [filters.tab, filters.supplier, filters.warehouse, filters.created_from, filters.created_to], () => ((filters.page = 1), load()));
watch(ordering, () => ((filters.tab = ''), (filters.page = 1), load()));

const newLink = computed(() => (ordering.value ? { path: '/purchasing/new', query: { mode: 'order' } } : '/purchasing/new'));
</script>

<template>
    <PageHeader :title="ordering ? 'Đặt hàng nhập' : 'Nhập hàng'">
        <template #links>
            <HeaderLink v-if="!ordering" :icon="Truck" to="/purchasing/orders">Đặt hàng nhập</HeaderLink>
            <HeaderLink v-else :icon="Truck" to="/purchasing">Nhập hàng</HeaderLink>
            <HeaderLink :icon="Undo2" to="/purchasing/returns">Trả hàng nhà cung cấp</HeaderLink>
            <HeaderLink :icon="Lightbulb" to="/purchasing/reorder">Gợi ý nhập hàng</HeaderLink>
        </template>
        <RouterLink v-if="tenant.can('purchasing.manage')" :to="newLink">
            <Button data-testid="new-po"><Plus /> {{ ordering ? 'Tạo đơn đặt hàng' : 'Tạo đơn nhập hàng' }}</Button>
        </RouterLink>
    </PageHeader>
    <Card>
        <div class="border-b px-3"><Tabs v-model="filters.tab" :tabs="tabs" variant="underline" data-testid="po-tabs" /></div>
        <div class="flex flex-wrap gap-3 border-b p-4">
            <div class="relative w-full sm:min-w-64 sm:flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" class="pl-8" :placeholder="ordering ? 'Tìm theo mã đơn đặt, nhà cung cấp, SKU' : 'Tìm theo mã phiếu nhập, nhà cung cấp, SKU, tham chiếu'" data-testid="po-search" />
            </div>
            <Select v-model="filters.warehouse" class="w-full sm:w-44" aria-label="Kho nhập" :options="[{ value: '', label: 'Kho nhập' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
            <Select v-model="filters.supplier" class="w-full sm:w-48" aria-label="Nhà cung cấp" :options="[{ value: '', label: 'Nhà cung cấp' }, ...suppliers.map((s) => ({ value: s.id, label: s.name }))]" />
            <label class="flex items-center gap-1.5 text-sm text-muted-foreground">
                Từ <Input v-model="filters.created_from" type="date" class="w-36" aria-label="Tạo từ ngày" />
                đến <Input v-model="filters.created_to" type="date" class="w-36" aria-label="Tạo đến ngày" />
            </label>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="ClipboardList"
            :title="ordering ? 'Chưa có đơn đặt hàng nhập' : 'Chưa có phiếu nhập'"
            description="Tạo đơn để đặt hàng nhà cung cấp, sau đó nhập hàng một hoặc nhiều đợt. Mỗi đợt nhập tạo phiếu nhập kho và cập nhật giá vốn."
        />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="po-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">{{ ordering ? 'Mã đơn đặt' : 'Mã phiếu nhập' }}</th>
                        <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th v-if="!ordering" class="px-4 py-2.5 font-medium">Trạng thái nhập</th>
                        <th class="px-4 py-2.5 font-medium">{{ ordering ? 'Ngày hẹn giao' : 'Kho nhập' }}</th>
                        <th class="px-4 py-2.5 font-medium">Nhà cung cấp</th>
                        <th class="px-4 py-2.5 font-medium">Nhân viên tạo</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá trị đơn</th>
                        <th v-if="!ordering" class="px-4 py-2.5 text-right font-medium">Còn phải trả</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="po in rows" :key="po.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <RouterLink :to="`/purchasing/${po.id}`" class="font-medium text-primary hover:underline">{{ po.number }}</RouterLink>
                            <p v-if="po.reference" class="text-xs text-muted-foreground">{{ po.reference }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDateTime(po.created_at) }}</td>
                        <td class="px-4 py-3">
                            <Badge v-if="ordering" :tone="poStatus[po.status]?.tone">{{ poStatus[po.status]?.label }}</Badge>
                            <Badge v-else :tone="tradeStatus(po.status).tone">{{ tradeStatus(po.status).label }}</Badge>
                        </td>
                        <td v-if="!ordering" class="px-4 py-3"><Badge :tone="receiveStatus(po.status).tone">{{ receiveStatus(po.status).label }}</Badge></td>
                        <td class="px-4 py-3">{{ ordering ? (po.expected_at ? formatDate(po.expected_at) : '—') : po.warehouse?.name }}</td>
                        <td class="px-4 py-3">{{ po.supplier?.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ po.created_by ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(po.total) }}</td>
                        <td v-if="!ordering" class="px-4 py-3 text-right tabular-nums" :class="owed(po) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ owed(po) > 0 ? formatMoney(owed(po)) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>
</template>
