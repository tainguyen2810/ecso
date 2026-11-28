<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { CalendarClock, CalendarX, Download, FileBarChart, HandCoins, Handshake, PackagePlus, Plus, Trophy, Truck, Undo2 } from 'lucide-vue-next';
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
import SupplierDialog from './SupplierDialog.vue';
import { card, initials, rowButton, shortMoney, th, thead } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Supplier } from '@/shared/types';

/**
 * "Nhà cung cấp" (mẫu Tailwind 28/11): still owed and overdue, received this month, suppliers in business; the list with
 * each supplier's debt and due date, "Trả tiền" on those owed; side cards for the next orders to pay and this month's top.
 */
interface Summary {
    counts: Record<'all' | 'active' | 'inactive' | 'debt', number>;
    owed: { value: number; orders: number; suppliers: number; overdue: number; overdue_orders: number; overdue_suppliers: number; oldest_days: number };
    month: { value: number; receipts: number; suppliers: number; top: { id: string; name: string; value: number }[] };
    due: { id: string; number: string; supplier: string; supplier_id: string; open: number; due_date: string | null; overdue: boolean; days: number | null }[];
}

provide('soft-cards', true);
const tenant = useTenantStore();
const rows = ref<Supplier[]>([]);
const summary = ref<Summary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 30 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = ref('');
const sort = ref('name');
const tab = ref<'all' | 'active' | 'inactive' | 'debt'>('all');
const page = ref(1);
const dialog = ref(false);

const tabs = computed(() => {
    const c = summary.value?.counts;
    return [
        { value: 'all', label: 'Tất cả', count: c?.all },
        { value: 'active', label: 'Đang giao dịch', count: c?.active },
        { value: 'debt', label: 'Còn nợ', count: c?.debt },
        { value: 'inactive', label: 'Ngừng giao dịch', count: c?.inactive },
    ];
});
const tabModel = computed({ get: () => tab.value as string, set: (v: string) => (tab.value = v as typeof tab.value) });
const topMax = computed(() => Math.max(1, ...(summary.value?.month.top.map((t) => t.value) ?? [1])));
const canPay = computed(() => tenant.can('purchasing.payment'));
const dueText = (s: Supplier) => {
    if (Number(s.overdue ?? 0) > 0 && s.next_due) return `Quá hạn ${daysFrom(s.next_due)} ngày`;
    return s.next_due ? `Hạn ${formatDate(s.next_due)}` : '';
};
function daysFrom(date: string) {
    return Math.max(1, Math.round((Date.now() - new Date(`${date}T00:00:00`).getTime()) / 86_400_000));
}
const dayMonth = (date: string | null) => (date ? { d: date.slice(8, 10), m: `Th${Number(date.slice(5, 7))}` } : null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/suppliers', { params: { q: q.value || undefined, sort: sort.value, debt: tab.value === 'debt' ? 1 : undefined, active: tab.value === 'active' ? 1 : tab.value === 'inactive' ? 0 : undefined, page: page.value } });
        rows.value = data.data;
        meta.value = { per_page: 30, ...data.meta };
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/suppliers/summary').then(({ data }) => (summary.value = data.data)).catch(() => {});
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([sort, tab], () => ((page.value = 1), load()));
const exporting = ref(false);
async function exportXlsx() {
    exporting.value = true;
    try {
        await downloadFile('/suppliers/export', 'nha-cung-cap.xlsx');
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <ProductsHead title="Nhà cung cấp" description="Danh bạ nhà cung cấp, tổng nhập, công nợ và hạn thanh toán của từng nơi.">
        <template #links>
            <HeadLink :icon="Truck" to="/purchasing">Nhập hàng</HeadLink>
            <HeadLink :icon="Undo2" to="/purchasing/returns">Trả hàng nhà cung cấp</HeadLink>
            <HeadLink v-if="tenant.can('reports.view')" :icon="FileBarChart" to="/reports/view/payables">Báo cáo công nợ nhà cung cấp</HeadLink>
        </template>
        <HeadButton :icon="Download" :disabled="exporting" @click="exportXlsx">Xuất file</HeadButton>
        <HeadButton v-if="tenant.can('purchasing.manage')" :icon="Plus" primary data-testid="add-supplier" @click="dialog = true">Thêm nhà cung cấp</HeadButton>
    </ProductsHead>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="supplier-kpis">
        <ReportKpi label="Còn phải trả" tip="Hàng đã nhận trừ trả lại và tiền đã trả, tiền trả trước được trừ vào phiếu cũ nhất." :icon="HandCoins" primary :value="summary ? Math.round(summary.owed.value) : null">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(summary?.owed.suppliers ?? 0) }} nhà cung cấp</ReportChip></template>
            {{ formatNumber(summary?.owed.orders ?? 0) }} phiếu nhập chưa trả đủ
        </ReportKpi>
        <ReportKpi label="Quá hạn thanh toán" tip="Phần còn nợ đã qua hạn trả của phiếu (hạn riêng hoặc số ngày được nợ của nhà cung cấp)." :icon="CalendarX" tone="red" :value="summary ? Math.round(summary.owed.overdue) : null">
            <template #chips>
                <ReportChip tone="red">{{ formatNumber(summary?.owed.overdue_orders ?? 0) }} phiếu</ReportChip>
                <ReportChip tone="red">{{ formatNumber(summary?.owed.overdue_suppliers ?? 0) }} nhà cung cấp</ReportChip>
            </template>
            {{ summary?.owed.oldest_days ? `Quá lâu nhất ${formatNumber(summary.owed.oldest_days)} ngày` : 'Chưa có khoản nào quá hạn' }}
        </ReportKpi>
        <ReportKpi label="Nhập tháng này" tip="Giá trị hàng đã nhập kho từ phiếu nhập trong tháng, theo giá nhập." :icon="PackagePlus" tone="violet" plain :value="summary ? Math.round(summary.month.value) : null">
            <template #chips><ReportChip tone="blue">{{ formatNumber(summary?.month.receipts ?? 0) }} phiếu</ReportChip></template>
            <template v-if="summary?.month.top[0] && summary.month.value">{{ summary.month.top[0].name }} chiếm {{ Math.round((summary.month.top[0].value / summary.month.value) * 100) }}%</template>
            <template v-else>Chưa nhập hàng tháng này</template>
        </ReportKpi>
        <ReportKpi label="Đang hợp tác" :icon="Handshake" tone="green" plain :value="summary?.counts.active ?? null" unit="">
            <template #chips><ReportChip>{{ formatNumber(summary?.counts.inactive ?? 0) }} ngừng giao dịch</ReportChip></template>
            {{ formatNumber(summary?.month.suppliers ?? 0) }} nhà cung cấp có hàng nhập tháng này
        </ReportKpi>
    </div>

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_380px]">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <LineTabs v-model="tabModel" :tabs="tabs" />
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                <SearchField v-model="q" placeholder="Tìm theo mã, tên, số điện thoại nhà cung cấp" />
                <Select v-model="sort" class="w-full sm:w-60" :options="[{ value: 'name', label: 'Sắp xếp: Tên A→Z' }, { value: 'debt', label: 'Sắp xếp: Công nợ cao trước' }, { value: 'purchased', label: 'Sắp xếp: Nhập nhiều' }, { value: 'newest', label: 'Sắp xếp: Mới thêm' }]" aria-label="Sắp xếp" />
            </div>
            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="Truck" title="Chưa có nhà cung cấp" description="Thêm nhà cung cấp để lập đơn nhập hàng và theo dõi công nợ." />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full min-w-[900px] text-[14px]" data-testid="supplier-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Nhà cung cấp</th>
                            <th :class="th">Liên hệ</th>
                            <th :class="cn(th, 'text-right')">Đơn nhập</th>
                            <th :class="cn(th, 'text-right')">Tổng đã nhập</th>
                            <th :class="cn(th, 'text-right')">Công nợ hiện tại</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in rows" :key="s.id" :class="cn('cursor-pointer border-t border-slate-100 hover:bg-slate-50/70', !s.is_active && 'opacity-60')" @click="$router.push(`/purchasing/suppliers/${s.id}`)">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-blue-50 text-[13px] font-semibold text-blue-700">{{ initials(s.name) }}</span>
                                    <div class="min-w-0">
                                        <RouterLink :to="`/purchasing/suppliers/${s.id}`" class="font-semibold text-blue-700 hover:underline" @click.stop>{{ s.name }}</RouterLink>
                                        <p class="text-[12px] text-slate-500">{{ s.code }}<template v-if="s.contact_name"> · {{ s.contact_name }}</template></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="tabular-nums text-slate-800">{{ s.phone ?? '—' }}</p>
                                <p class="max-w-56 truncate text-[12px] text-slate-500">{{ s.email ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-600">{{ formatNumber(s.orders_count ?? 0) }} phiếu</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-800">{{ formatNumber(Math.round(Number(s.purchased ?? 0))) }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <p :class="cn('font-semibold tabular-nums', Number(s.overdue ?? 0) > 0 ? 'text-red-600' : Number(s.debt) > 0 ? 'text-slate-900' : 'text-slate-400')">{{ formatNumber(Math.round(Number(s.debt ?? 0))) }}</p>
                                <p v-if="Number(s.debt) > 0 && dueText(s)" :class="cn('text-[12px]', Number(s.overdue ?? 0) > 0 ? 'text-red-500' : 'text-slate-500')">{{ dueText(s) }}</p>
                            </td>
                            <td class="px-4 py-3.5"><StatusChip :tone="s.is_active ? 'green' : 'slate'">{{ s.is_active ? 'Đang giao dịch' : 'Ngừng giao dịch' }}</StatusChip></td>
                            <td class="px-4 py-3.5 text-right">
                                <RouterLink v-if="canPay && Number(s.debt) > 0" :to="{ path: `/purchasing/suppliers/${s.id}`, query: { pay: '1' } }" :class="rowButton" @click.stop>Trả tiền</RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page" :count="rows.length" unit="nhà cung cấp" @go="(p) => ((page = p), load())" />
        </section>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard title="Cần trả sớm" description="Theo hạn thanh toán của từng phiếu nhập">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-red-50 text-red-500"><CalendarClock class="size-5" /></span></template>
                <ul v-if="summary?.due.length" class="divide-y divide-slate-100">
                    <li v-for="d in summary.due" :key="d.id">
                        <RouterLink :to="`/purchasing/${d.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                            <span :class="cn('grid size-11 shrink-0 place-items-center rounded-[10px] leading-none', d.overdue ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-700')">
                                <template v-if="dayMonth(d.due_date)"><span class="text-center"><b class="block text-[16px]">{{ dayMonth(d.due_date)!.d }}</b><span class="text-[11px]">{{ dayMonth(d.due_date)!.m }}</span></span></template>
                                <span v-else class="text-[11px]">Chưa hẹn</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-slate-800">{{ d.supplier }}</span>
                                <span class="block text-[12px] text-slate-500">{{ d.number }}<template v-if="d.overdue && d.days !== null"> · quá {{ Math.abs(d.days) }} ngày</template></span>
                            </span>
                            <span :class="cn('text-[14px] font-semibold tabular-nums', d.overdue ? 'text-red-600' : 'text-slate-900')">{{ shortMoney(d.open) }}</span>
                        </RouterLink>
                    </li>
                </ul>
                <p v-else class="px-5 py-4 text-[14px] text-slate-500">Không còn khoản nào phải trả.</p>
            </SideCard>
            <SideCard title="Nhập nhiều nhất tháng này" description="Theo giá trị phiếu nhập">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-violet-50 text-violet-600"><Trophy class="size-5" /></span></template>
                <ul v-if="summary?.month.top.length" class="space-y-4 p-5">
                    <li v-for="(t, i) in summary.month.top" :key="t.id">
                        <div class="flex justify-between text-[14px]">
                            <span class="truncate text-slate-800">{{ t.name }}</span>
                            <span class="tabular-nums text-slate-600">{{ shortMoney(t.value) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100">
                            <div :class="cn('h-full rounded-full', ['bg-blue-600', 'bg-blue-500', 'bg-sky-500', 'bg-cyan-500', 'bg-slate-400'][i])" :style="{ width: `${(t.value / topMax) * 100}%` }" />
                        </div>
                    </li>
                </ul>
                <p v-else class="px-5 py-4 text-[14px] text-slate-500">Chưa có phiếu nhập nào trong tháng này.</p>
            </SideCard>
        </aside>
    </div>
    <SupplierDialog v-model:open="dialog" @saved="(s) => $router.push(`/purchasing/suppliers/${s.id}`)" />
</template>
