<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, CalendarDays, CalendarX, CircleCheck, ClipboardList, Clock, FileCode2, HandCoins, Lightbulb, PackagePlus, Plus, ScanLine, ShoppingBag, Truck, Undo2 } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadLink from '../products/ui/HeadLink.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import SearchField from '../products/ui/SearchField.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, initials, rowButton, shortMoney, th, thead, type ChipTone } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { owed, poStatus, receiveStatus, supplierPaymentMethods, tradeStatus } from './labels';
import type { PurchaseOrderData, Supplier, Warehouse } from '@/shared/types';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

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
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
provide('soft-cards', true);
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ q: '', tab: String(route.query.status ?? ''), supplier: '', warehouse: '', created_from: '', created_to: '', page: 1 });

/** Figures above the list and tab counts (GET /purchase-orders/summary). */
type Money = { count: number; value: number };
type OrderingSummary = {
    counts: Record<string, number>;
    waiting: Money & { quantity: number };
    late: Money;
    soon: Money;
    month: Money & { previous: number; suppliers: number };
    calendar: { id: string; number: string; supplier: string | null; expected_at: string; total: string; quantity: number; late: boolean }[];
};
type ReceivingSummary = {
    counts: Record<string, number>;
    month: { value: number; quantity: number; receipts: number; previous: number };
    pending: Money;
    owed: { value: number; orders: number; overdue: number; overdue_orders: number; suppliers: number };
    paid: { value: number; by_method: Record<string, number> };
};
const ord = ref<OrderingSummary | null>(null);
const rec = ref<ReceivingSummary | null>(null);
async function loadSummary() {
    const { data } = await api.get('/purchase-orders/summary', { params: { mode: ordering.value ? 'ordering' : 'receiving' } }).catch(() => ({ data: { data: null } }));
    if (ordering.value) ord.value = data.data;
    else rec.value = data.data;
}
const counts = computed<Record<string, number>>(() => (ordering.value ? ord.value?.counts : rec.value?.counts) ?? {});
const tabs = computed(() =>
    ordering.value
        ? [
              { value: '', label: 'Tất cả đơn đặt', count: counts.value.all },
              { value: 'draft', label: 'Nháp', count: counts.value.draft },
              { value: 'ordered', label: 'Đã đặt, chờ hàng', count: counts.value.ordered },
          ]
        : [
              { value: '', label: 'Tất cả phiếu nhập', count: counts.value.all },
              { value: 'trading', label: 'Đang giao dịch', count: counts.value.trading },
              { value: 'done', label: 'Hoàn thành', count: counts.value.done },
              { value: 'unpaid', label: 'Còn nợ NCC', count: counts.value.unpaid },
              { value: 'cancelled', label: 'Đã hủy', count: counts.value.cancelled || null },
          ],
);
const change = (now: number, before: number) => {
    if (!before) return null;
    const pct = ((now - before) / before) * 100;
    return { up: pct >= 0, text: `${pct >= 0 ? '+' : '−'}${Math.abs(pct).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%` };
};
const today = new Date().toISOString().slice(0, 10);
/** Days from today to a due date (negative: late). */
const daysTo = (d: string) => Math.round((Date.parse(d) - Date.parse(today)) / 86_400_000);
const dueText = (d: string) => {
    const n = daysTo(d);
    return n < 0 ? `Quá ${-n} ngày` : n === 0 ? 'Hôm nay' : `Còn ${n} ngày`;
};
const chipTone: Record<string, ChipTone> = { success: 'green', danger: 'red', warning: 'amber', muted: 'slate', default: 'blue', info: 'violet' };
const payStatus = (po: PurchaseOrderData): [string, ChipTone] | null => {
    if (po.status === 'cancelled' || Number(po.received_value) <= 0) return null;
    const left = po.allocation?.open ?? owed(po);
    if (left <= 0) return ['Đã trả', 'green'];
    return Number(po.paid_amount) > 0 ? ['Trả một phần', 'blue'] : ['Chưa trả', 'amber'];
};
const progress = (po: PurchaseOrderData) => (po.total_quantity ? Math.min(100, Math.round(((po.received_quantity ?? 0) / po.total_quantity) * 100)) : 0);

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
    loadSummary();
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
watch(ordering, () => ((filters.tab = ''), (filters.page = 1), load(), loadSummary()));

const newLink = computed(() => (ordering.value ? { path: '/purchasing/new', query: { mode: 'order' } } : '/purchasing/new'));
</script>

<template>
    <ProductsHead
        :title="ordering ? 'Đặt hàng nhập' : 'Nhập hàng'"
        :description="ordering ? 'Đặt hàng nhà cung cấp trước, hàng về thì nhập một hoặc nhiều đợt. Mỗi đợt tạo phiếu nhập kho.' : 'Mỗi phiếu nhập cộng tồn vào kho, tính lại giá vốn bình quân và ghi công nợ nhà cung cấp.'"
    >
        <template #links>
            <HeadLink v-if="!ordering" :icon="Truck" to="/purchasing/orders">Đặt hàng nhập</HeadLink>
            <HeadLink v-else :icon="PackagePlus" to="/purchasing">Nhập hàng</HeadLink>
            <HeadLink :icon="FileCode2" to="/purchasing/invoices">Hoá đơn mua vào</HeadLink>
            <HeadLink :icon="Undo2" to="/purchasing/returns">Trả hàng nhà cung cấp</HeadLink>
        </template>
        <HeadButton v-if="tenant.can('purchasing.manage') && !ordering" :icon="ScanLine" to="/purchasing/scan" data-testid="scan-invoice">Chụp hoá đơn</HeadButton>
        <HeadButton :icon="Lightbulb" to="/purchasing/reorder">Gợi ý nhập hàng</HeadButton>
        <HeadButton v-if="tenant.can('purchasing.manage')" :icon="Plus" :to="newLink" primary data-testid="new-po">{{ ordering ? 'Tạo đơn đặt hàng' : 'Tạo đơn nhập hàng' }}</HeadButton>
    </ProductsHead>

    <div v-if="ordering" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="po-kpis">
        <ReportKpi label="Đang chờ hàng về" tip="Đơn đã đặt với nhà cung cấp, chưa nhập đợt nào." :icon="Clock" primary :value="ord ? ord.waiting.value : null">
            <template #chips>
                <ReportChip tone="onblue">{{ formatNumber(ord?.waiting.count ?? 0) }} đơn</ReportChip>
                <ReportChip tone="onblue">{{ formatNumber(ord?.waiting.quantity ?? 0) }} sp</ReportChip>
            </template>
            {{ ord?.counts.draft ? `Thêm ${formatNumber(ord.counts.draft)} đơn nháp chưa gửi` : 'Không có đơn nháp' }}
        </ReportKpi>
        <ReportKpi label="Quá ngày hẹn giao" :icon="CalendarX" tone="red" :value="ord?.late.count ?? null" unit="đơn">
            <template #chips><ReportChip tone="red">{{ formatMoney(ord?.late.value ?? 0) }}</ReportChip></template>
            Nhắc nhà cung cấp hoặc đổi ngày hẹn
        </ReportKpi>
        <ReportKpi label="Giao trong 7 ngày tới" :icon="Truck" tone="amber" :value="ord?.soon.count ?? null" unit="đơn">
            <template #chips><ReportChip tone="amber">{{ formatMoney(ord?.soon.value ?? 0) }}</ReportChip></template>
            Chuẩn bị chỗ trong kho
        </ReportKpi>
        <ReportKpi label="Đặt tháng này" :icon="ShoppingBag" tone="violet" plain :value="ord ? ord.month.value : null">
            <template #chips>
                <ReportChip>{{ formatNumber(ord?.month.count ?? 0) }} đơn</ReportChip>
                <ReportChip v-if="ord && change(ord.month.value, ord.month.previous)" :tone="change(ord.month.value, ord.month.previous)!.up ? 'up' : 'plain'">{{ change(ord.month.value, ord.month.previous)!.text }} so với tháng trước</ReportChip>
            </template>
            {{ formatNumber(ord?.month.suppliers ?? 0) }} nhà cung cấp
        </ReportKpi>
    </div>
    <div v-else class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="po-kpis">
        <ReportKpi label="Nhập tháng này" tip="Giá trị hàng đã nhập kho từ phiếu nhập trong tháng, theo giá nhập." :icon="PackagePlus" primary :value="rec ? Math.round(rec.month.value) : null">
            <template #chips>
                <ReportChip tone="onblue">{{ formatNumber(rec?.month.receipts ?? 0) }} lần nhập</ReportChip>
                <ReportChip tone="onblue">{{ formatNumber(rec?.month.quantity ?? 0) }} sp</ReportChip>
            </template>
            Tháng trước {{ formatMoney(rec?.month.previous ?? 0) }}<template v-if="rec && change(rec.month.value, rec.month.previous)"> ({{ change(rec.month.value, rec.month.previous)!.text }})</template>
        </ReportKpi>
        <ReportKpi label="Chờ nhập kho" tip="Đơn đã đặt hoặc mới nhập một phần: hàng còn lại chưa vào kho." :icon="Clock" tone="amber" :value="rec?.pending.count ?? null" unit="phiếu">
            <template #chips><ReportChip tone="amber">{{ formatMoney(rec?.pending.value ?? 0) }} chưa về</ReportChip></template>
            Đã tạo phiếu, hàng chưa vào kho hết
        </ReportKpi>
        <ReportKpi label="Còn phải trả nhà cung cấp" tip="Hàng đã nhận trừ trả lại và tiền đã trả, tiền trả trước được trừ vào đơn cũ nhất." :icon="HandCoins" tone="red" :value="rec ? Math.round(rec.owed.value) : null">
            <template #chips>
                <ReportChip tone="red">{{ formatNumber(rec?.owed.orders ?? 0) }} phiếu</ReportChip>
                <ReportChip v-if="rec?.owed.overdue_orders" tone="red">{{ formatNumber(rec.owed.overdue_orders) }} quá hạn</ReportChip>
            </template>
            {{ rec?.owed.overdue ? `Quá hạn ${formatMoney(rec.owed.overdue)}` : 'Chưa có khoản nào quá hạn' }}
        </ReportKpi>
        <ReportKpi label="Đã trả tháng này" :icon="CircleCheck" tone="green" plain :value="rec ? Math.round(rec.paid.value) : null">
            <template #chips>
                <ReportChip v-if="rec && rec.month.value" tone="up">{{ Math.round((rec.paid.value / rec.month.value) * 100) }}% giá trị nhập</ReportChip>
            </template>
            <template v-if="rec && Object.keys(rec.paid.by_method).length">{{ Object.entries(rec.paid.by_method).slice(0, 2).map(([m, v]) => `${supplierPaymentMethods[m] ?? m} ${shortMoney(v)}`).join(' · ') }}</template>
            <template v-else>Chưa trả khoản nào trong tháng</template>
        </ReportKpi>
    </div>

    <div :class="cn('grid gap-5', ordering && '2xl:grid-cols-[minmax(0,1fr)_380px]')">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <LineTabs v-model="filters.tab" :tabs="tabs" data-testid="po-tabs" />
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                <SearchField v-model="filters.q" :placeholder="ordering ? 'Tìm theo mã đơn đặt, nhà cung cấp, SKU' : 'Tìm theo mã phiếu nhập, nhà cung cấp, SKU, tham chiếu'" data-testid="po-search" />
                <Select v-model="filters.supplier" class="w-full sm:w-52" aria-label="Nhà cung cấp" :options="[{ value: '', label: 'Nhà cung cấp' }, ...suppliers.map((s) => ({ value: s.id, label: s.name }))]" />
                <Select v-model="filters.warehouse" class="w-full sm:w-48" aria-label="Kho nhập" :options="[{ value: '', label: 'Kho nhập' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
                <DateRangeInput v-model:from="filters.created_from" v-model:to="filters.created_to" clearable placeholder="Ngày tạo" aria-label="Ngày tạo" />
            </div>
            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState
                v-else-if="!rows.length"
                :icon="ClipboardList"
                :title="ordering ? 'Chưa có đơn đặt hàng nhập' : 'Chưa có phiếu nhập'"
                description="Tạo đơn để đặt hàng nhà cung cấp, sau đó nhập hàng một hoặc nhiều đợt. Mỗi đợt nhập tạo phiếu nhập kho và cập nhật giá vốn."
            />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table v-if="ordering" class="w-full min-w-[900px]" data-testid="po-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Mã đơn đặt</th>
                            <th :class="th">Nhà cung cấp</th>
                            <th :class="th">Ngày hẹn giao</th>
                            <th :class="cn(th, 'text-right')">Số lượng</th>
                            <th :class="cn(th, 'text-right')">Giá trị đơn</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="po in rows" :key="po.id" class="border-t border-slate-100 hover:bg-slate-50/70">
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <RouterLink :to="`/purchasing/${po.id}`" class="font-mono text-[13px] font-semibold text-blue-700 hover:underline">{{ po.number }}</RouterLink>
                                <StatusChip v-if="po.imported_from" class="ml-1.5">Sapo</StatusChip>
                                <p class="text-[12px] text-slate-500">{{ formatDateTime(po.created_at) }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-600">{{ initials(po.supplier?.name ?? '?') }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[14px] font-medium text-slate-800">{{ po.supplier?.name }}</p>
                                        <p class="text-[12px] text-slate-500">{{ po.created_by ?? (po.imported_from ? 'Sapo' : '—') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <template v-if="po.expected_at">
                                    <p :class="cn('text-[14px]', po.status === 'ordered' && daysTo(po.expected_at) < 0 && 'font-medium text-red-600')">{{ formatDate(po.expected_at) }}</p>
                                    <p v-if="po.status === 'ordered'" :class="cn('text-[12px]', daysTo(po.expected_at) < 0 ? 'text-red-500' : daysTo(po.expected_at) <= 2 ? 'text-amber-600' : 'text-slate-500')">{{ dueText(po.expected_at) }}</p>
                                </template>
                                <span v-else class="text-[13px] text-slate-400">Chưa hẹn</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-[14px] tabular-nums text-slate-600">{{ formatNumber(po.total_quantity ?? 0) }} sp</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums">{{ formatMoney(po.total) }}</td>
                            <td class="px-4 py-3.5"><StatusChip :tone="po.status === 'draft' ? 'slate' : 'blue'">{{ po.status === 'ordered' ? 'Đã đặt, chờ hàng' : poStatus[po.status]?.label }}</StatusChip></td>
                            <td class="px-4 py-3.5 text-right">
                                <RouterLink v-if="tenant.can('purchasing.manage') && po.status === 'ordered'" :to="`/purchasing/${po.id}`" :class="rowButton">Nhập hàng</RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table v-else class="w-full min-w-[1100px]" data-testid="po-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Mã phiếu nhập</th>
                            <th :class="th">Nhà cung cấp, tham chiếu</th>
                            <th :class="th">Kho nhập</th>
                            <th :class="th">Hàng đã về</th>
                            <th :class="cn(th, 'text-right')">Giá trị đơn</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="cn(th, 'text-right')">Còn phải trả</th>
                            <th :class="th">Thanh toán</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="po in rows" :key="po.id" class="border-t border-slate-100 hover:bg-slate-50/70">
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <RouterLink :to="`/purchasing/${po.id}`" :class="cn('font-mono text-[13px] font-semibold hover:underline', po.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-blue-700')">{{ po.number }}</RouterLink>
                                <StatusChip v-if="po.imported_from" class="ml-1.5">Sapo</StatusChip>
                                <p class="text-[12px] text-slate-500">{{ formatDateTime(po.created_at) }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="max-w-[260px] truncate text-[14px] font-medium text-slate-800">{{ po.supplier?.name }}</p>
                                <p class="max-w-[260px] truncate text-[12px] text-slate-500">{{ po.reference || po.created_by || '—' }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-[14px] text-slate-600">{{ po.warehouse?.name }}</td>
                            <td class="w-[190px] px-4 py-3.5">
                                <div class="flex items-center justify-between text-[12px] text-slate-500">
                                    <span>{{ receiveStatus(po.status).label }}</span><span class="tabular-nums">{{ formatNumber(po.received_quantity ?? 0) }}/{{ formatNumber(po.total_quantity ?? 0) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div :class="cn('h-full rounded-full', progress(po) >= 100 ? 'bg-emerald-500' : 'bg-blue-600')" :style="{ width: `${progress(po)}%` }" /></div>
                            </td>
                            <td :class="cn('whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums', po.status === 'cancelled' && 'text-slate-400 line-through')">{{ formatMoney(po.total) }}</td>
                            <td class="px-4 py-3.5"><StatusChip :tone="chipTone[tradeStatus(po.status).tone] ?? 'slate'">{{ tradeStatus(po.status).label }}</StatusChip></td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right">
                                <template v-if="(po.allocation?.open ?? owed(po)) > 0">
                                    <p :class="cn('text-[14px] font-semibold tabular-nums', po.allocation?.overdue ? 'text-red-600' : 'text-slate-900')">{{ formatMoney(po.allocation?.open ?? owed(po)) }}</p>
                                    <p v-if="po.allocation?.due_date" :class="cn('text-[12px]', po.allocation.overdue ? 'text-red-500' : 'text-slate-500')">
                                        {{ po.allocation.overdue ? `Quá hạn ${-daysTo(po.allocation.due_date)} ngày` : `Hạn ${formatDate(po.allocation.due_date)}` }}
                                    </p>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3.5"><StatusChip v-if="payStatus(po)" :tone="payStatus(po)![1]">{{ payStatus(po)![0] }}</StatusChip></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page ?? 20" :count="rows.length" :unit="ordering ? 'đơn đặt' : 'phiếu nhập'" @go="(p) => ((filters.page = p), load())" />
        </section>

        <aside v-if="ordering" class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard title="Lịch hàng về" description="Đơn đã đặt, theo ngày hẹn giao">
                <ul v-if="ord?.calendar.length" class="divide-y divide-slate-100 py-1">
                    <li v-for="c in ord.calendar" :key="c.id">
                        <RouterLink :to="`/purchasing/${c.id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50">
                            <span :class="cn('w-12 shrink-0 rounded-[9px] py-1 text-center', c.late ? 'bg-red-50' : daysTo(c.expected_at) <= 2 ? 'bg-amber-50' : 'bg-slate-100')">
                                <span :class="cn('block text-[11px]', c.late ? 'text-red-500' : 'text-slate-500')">Th{{ Number(c.expected_at.slice(5, 7)) }}</span>
                                <span :class="cn('block text-[15px] font-bold', c.late ? 'text-red-600' : 'text-slate-800')">{{ c.expected_at.slice(8, 10) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-medium text-slate-800">{{ c.supplier }}</span>
                                <span class="block text-[12px] text-slate-500">{{ c.number }} · {{ c.late ? dueText(c.expected_at).toLowerCase() : `${formatNumber(c.quantity)} sp` }}</span>
                            </span>
                            <span class="text-[13px] font-semibold tabular-nums">{{ shortMoney(c.total) }}</span>
                        </RouterLink>
                    </li>
                </ul>
                <p v-else class="px-5 py-6 text-center text-[13px] text-slate-500"><CalendarDays class="mx-auto mb-2 size-6 text-slate-300" />Chưa có đơn nào hẹn ngày giao.</p>
            </SideCard>
            <SideCard title="Gợi ý nhập hàng" description="Phiên bản dưới tồn an toàn">
                <div class="px-5 py-4">
                    <p class="text-[13px] text-slate-600">Số lượng đề xuất tính theo tốc độ bán gần đây, đã trừ hàng đang đặt chờ về.</p>
                    <RouterLink to="/purchasing/reorder" class="mt-3 flex h-10 items-center justify-center gap-2 rounded-[10px] border border-blue-200 bg-blue-50 text-[14px] font-medium text-blue-700 hover:bg-blue-100">
                        Tạo đơn đặt từ gợi ý <ArrowRight class="size-4" />
                    </RouterLink>
                </div>
            </SideCard>
        </aside>
    </div>
</template>
