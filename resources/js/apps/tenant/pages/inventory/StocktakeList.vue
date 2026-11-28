<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { CalendarClock, CircleCheck, ClipboardCheck, ClipboardList, Plus, Scale, ScanLine, SlidersHorizontal } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import SearchField from '../products/ui/SearchField.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, rowPrimary, th, thead, type ChipTone } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { InventoryDocument, Warehouse } from '@/shared/types';

/**
 * "Kiểm hàng" (mẫu Tailwind 28/11): sheets being counted, balanced this month and what they found, variants nobody has
 * counted for a while; the list with each sheet's shortage and surplus, "Kiểm tiếp" on sheets still being counted.
 */
interface Summary {
    counts: Record<'all' | 'draft' | 'pending' | 'approved' | 'cancelled', number>;
    month: { sheets: number; skus: number; short_qty: number; surplus_qty: number; short_value: number; surplus_value: number };
    stale: { days: number; count: number; by_category: { name: string | null; count: number }[] };
}

provide('soft-cards', true);
const tenant = useTenantStore();
const rows = ref<InventoryDocument[]>([]);
const warehouses = ref<Warehouse[]>([]);
const summary = ref<Summary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ status: '', warehouse: '', q: '', page: 1 });

const tabs = computed(() => {
    const c = summary.value?.counts;
    return [
        { value: '', label: 'Tất cả', count: c?.all },
        { value: 'draft', label: 'Đang kiểm', count: c?.draft },
        { value: 'pending', label: 'Chờ duyệt', count: c?.pending },
        { value: 'approved', label: 'Đã cân bằng', count: c?.approved },
        { value: 'cancelled', label: 'Đã hủy', count: c?.cancelled },
    ];
});
const statusOf = (s?: string): { label: string; tone: ChipTone } =>
    ({ draft: { label: 'Đang kiểm', tone: 'blue' }, pending: { label: 'Chờ duyệt', tone: 'amber' }, approved: { label: 'Đã cân bằng', tone: 'green' }, rejected: { label: 'Từ chối', tone: 'red' } })[s ?? ''] as {
        label: string;
        tone: ChipTone;
    } ?? { label: 'Đã hủy', tone: 'slate' };
const linkOf = (d: InventoryDocument) => (d.status === 'draft' ? `/inventory/stocktake/${d.id}` : `/inventory/adjustments/${d.id}`);
const net = computed(() => (summary.value ? summary.value.month.surplus_value - summary.value.month.short_value : null));
const drafts = computed(() => rows.value.filter((d) => d.status === 'draft'));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/inventory/adjustments', {
            params: { reason: 'stocktake', status: filters.status || undefined, warehouse: filters.warehouse || undefined, q: filters.q || undefined, page: filters.page },
        });
        rows.value = data.data;
        meta.value = { per_page: 20, ...data.meta };
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    api.get('/inventory/adjustments/summary').then(({ data }) => (summary.value = data.data)).catch(() => {});
    warehouses.value = (await api.get('/warehouses')).data.data;
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(() => [filters.status, filters.warehouse], () => ((filters.page = 1), load()));
const canCount = computed(() => tenant.can('inventory.adjust'));
const steps = [
    ['Tạo phiếu, chọn kho', 'Chọn cả kho, theo danh mục hoặc từng SKU'],
    ['Quét mã hoặc nhập số đếm', 'Điện thoại quét được bằng camera, lưu nhiều lần'],
    ['Xem chênh lệch', 'Dòng lệch tô màu, ghi lý do nếu cần'],
    ['Cân bằng kho', 'Tồn kho cập nhật theo số đếm thực tế'],
];
</script>

<template>
    <ProductsHead title="Kiểm hàng" description="Tạo phiếu kiểm, quét mã hoặc nhập số đếm thực tế, lưu nhiều lần trong lúc kiểm, xong thì bấm Cân bằng kho.">
        <HeadButton :icon="SlidersHorizontal" to="/inventory/adjustments">Điều chỉnh tồn</HeadButton>
        <HeadButton v-if="canCount" :icon="Plus" primary to="/inventory/stocktake/new" data-testid="new-stocktake">Tạo phiếu kiểm</HeadButton>
    </ProductsHead>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="stocktake-kpis">
        <ReportKpi label="Đang kiểm" tip="Phiếu đã lưu, đang đếm, chưa cân bằng nên chưa đổi tồn." :icon="ClipboardList" primary :value="summary?.counts.draft ?? null" unit="phiếu">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(summary?.counts.pending ?? 0) }} chờ duyệt</ReportChip></template>
            {{ drafts.length ? drafts.map((d) => d.warehouse?.name).filter((v, i, a) => v && a.indexOf(v) === i).join(', ') : 'Không có phiếu nào đang đếm' }}
        </ReportKpi>
        <ReportKpi label="Cân bằng tháng này" :icon="CircleCheck" tone="green" plain :value="summary?.month.sheets ?? null" unit="phiếu">
            <template #chips><ReportChip tone="up">{{ formatNumber(summary?.month.skus ?? 0) }} SKU đã kiểm</ReportChip></template>
            Tồn đã cập nhật theo số đếm
        </ReportKpi>
        <ReportKpi label="Lệch sau kiểm (tháng này)" tip="Thừa trừ thiếu của các phiếu đã cân bằng trong tháng, tính theo giá vốn hiện tại." :icon="Scale" :tone="(net ?? 0) < 0 ? 'red' : 'green'" :value="net === null ? null : Math.round(net)">
            <template #chips>
                <ReportChip tone="red">Thiếu {{ formatNumber(summary?.month.short_qty ?? 0) }} sp</ReportChip>
                <ReportChip tone="up">Thừa {{ formatNumber(summary?.month.surplus_qty ?? 0) }} sp</ReportChip>
            </template>
            Theo giá vốn hiện tại
        </ReportKpi>
        <ReportKpi label="Lâu chưa kiểm" :tip="`Phiên bản đang bán chưa có trong phiếu kiểm nào được cân bằng ${summary?.stale.days ?? 60} ngày qua.`" :icon="CalendarClock" tone="amber" :value="summary?.stale.count ?? null" unit="SKU">
            <template #chips><ReportChip tone="amber">Trên {{ summary?.stale.days ?? 60 }} ngày</ReportChip></template>
            <template v-if="summary?.stale.by_category[0]">Nên kiểm nhóm {{ summary.stale.by_category[0].name ?? 'chưa phân loại' }} trước</template>
            <template v-else>Mọi phiên bản đều đã kiểm gần đây</template>
        </ReportKpi>
    </div>

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_380px]">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <LineTabs v-model="filters.status" :tabs="tabs" />
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                <SearchField v-model="filters.q" placeholder="Tìm theo mã phiếu kiểm hàng, ghi chú" />
                <Select v-model="filters.warehouse" class="w-full sm:w-52" aria-label="Kho" :options="[{ value: '', label: 'Kho: Tất cả' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
            </div>
            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="ClipboardCheck" title="Chưa có phiếu kiểm" description="Tạo phiếu kiểm, quét mã hoặc nhập số đếm thực tế, lưu lại nhiều lần trong lúc kiểm, xong thì bấm Cân bằng kho." />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full min-w-[860px]" data-testid="stocktake-list">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Mã phiếu kiểm</th>
                            <th :class="th">Kho, ghi chú</th>
                            <th :class="th">Nhân viên tạo</th>
                            <th :class="cn(th, 'text-right')">SKU</th>
                            <th :class="th">Chênh lệch</th>
                            <th :class="th">Cân bằng lúc</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody class="text-[14px]">
                        <tr v-for="d in rows" :key="d.id" class="border-t border-slate-100 hover:bg-slate-50/70">
                            <td class="px-4 py-3.5">
                                <RouterLink :to="linkOf(d)" :class="cn('font-mono text-[13px] font-semibold text-blue-700 hover:underline', d.status === 'cancelled' && 'text-slate-400 line-through')">{{ d.number }}</RouterLink>
                                <p class="text-[12px] text-slate-500">{{ formatDateTime(d.created_at) }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="font-medium text-slate-800">{{ d.warehouse?.name }}</p>
                                <p class="max-w-64 truncate text-[12px] text-slate-500">{{ d.note || '—' }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="text-slate-800">{{ d.created_by ?? '—' }}</p>
                                <p class="text-[12px] text-slate-500">Người tạo</p>
                            </td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ d.lines_count != null ? `${formatNumber(d.lines_count)} SKU` : '—' }}</td>
                            <td class="px-4 py-3.5">
                                <template v-if="d.status === 'approved'">
                                    <span v-if="!d.short_qty && !d.surplus_qty" class="text-[13px] font-medium text-emerald-600">Khớp hết</span>
                                    <div v-else class="flex flex-wrap gap-1.5">
                                        <StatusChip v-if="d.short_qty" tone="red">Thiếu {{ formatNumber(Math.abs(d.short_qty)) }}</StatusChip>
                                        <StatusChip v-if="d.surplus_qty" tone="green">Thừa {{ formatNumber(d.surplus_qty) }}</StatusChip>
                                    </div>
                                </template>
                                <span v-else-if="d.status === 'draft' || d.status === 'pending'" class="text-[13px] text-slate-400">Chưa cân bằng</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-3.5">
                                <template v-if="d.status === 'approved'">
                                    <p class="whitespace-nowrap text-slate-800">{{ formatDateTime(d.decided_at) }}</p>
                                    <p class="text-[12px] text-slate-500">{{ d.decided_by ?? '—' }}</p>
                                </template>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-3.5"><StatusChip :tone="statusOf(d.status).tone">{{ statusOf(d.status).label }}</StatusChip></td>
                            <td class="px-4 py-3.5 text-right">
                                <RouterLink v-if="d.status === 'draft' && canCount" :to="linkOf(d)" :class="rowPrimary">Kiểm tiếp</RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page" :count="rows.length" unit="phiếu kiểm" @go="(p) => ((filters.page = p), load())" />
        </section>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard title="Các bước kiểm hàng" description="Làm được trên máy tính và điện thoại">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><ScanLine class="size-5" /></span></template>
                <ol class="space-y-4 px-5 py-4">
                    <li v-for="([title, hint], i) in steps" :key="i" class="flex gap-3">
                        <span :class="cn('grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-semibold', i === 0 ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600')">{{ i + 1 }}</span>
                        <div>
                            <p class="text-[14px] font-medium text-slate-800">{{ title }}</p>
                            <p class="text-[12px] text-slate-500">{{ hint }}</p>
                        </div>
                    </li>
                </ol>
            </SideCard>
            <SideCard title="Lâu chưa kiểm" :description="`Theo danh mục, trên ${summary?.stale.days ?? 60} ngày`">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><CalendarClock class="size-5" /></span></template>
                <ul v-if="summary?.stale.by_category.length" class="divide-y divide-slate-100 px-5 text-[14px]">
                    <li v-for="c in summary.stale.by_category" :key="c.name ?? ''" class="flex justify-between py-3">
                        <span class="text-slate-800">{{ c.name ?? 'Chưa phân loại' }}</span>
                        <span class="tabular-nums text-slate-500">{{ formatNumber(c.count) }} SKU</span>
                    </li>
                </ul>
                <p v-else class="px-5 py-4 text-[14px] text-slate-500">Mọi phiên bản đều đã được kiểm gần đây.</p>
                <div v-if="canCount && summary?.stale.count" class="px-5 pb-5 pt-1">
                    <RouterLink to="/inventory/stocktake/new" class="flex h-10 items-center justify-center rounded-[10px] border border-blue-200 bg-blue-50 text-[14px] font-medium text-blue-700 hover:bg-blue-100">Tạo phiếu kiểm mới</RouterLink>
                </div>
            </SideCard>
        </aside>
    </div>
</template>
