<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { ArrowDownToLine, ArrowUpFromLine, Boxes, ChevronLeft, ChevronRight, Package, Search, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

/**
 * Xuất nhập tồn (mẫu Tailwind 28/11): opening, in, out and closing of the period; one row per SKU with a bar of what is
 * left from the opening stock and what was added; "Chi tiết" splits in and out by kind.
 */
import type { Warehouse } from '@/shared/types';

const COLUMNS = {
    purchase_in: 'Nhập mua',
    return_in: 'Khách hoàn',
    transfer_in: 'Chuyển đến',
    adjust_in: 'Đ/c tăng',
    sale_out: 'Xuất bán',
    supplier_out: 'Trả NCC',
    damage_out: 'Hủy / mất',
    adjust_out: 'Đ/c giảm',
    transfer_out: 'Chuyển đi',
} as const;
type Col = keyof typeof COLUMNS;
type Row = { variant: { id: string; sku: string; name: string }; opening: number; in: number; out: number; closing: number; unit_cost: number; closing_value: number } & Record<Col, number>;
interface LedgerData {
    rows: Row[];
    totals: Record<string, number>;
}

provide('soft-cards', true);
const { range } = useReportQuery();
const warehouses = ref<Warehouse[]>([]);
const warehouse = ref('');
const q = ref('');
const detail = ref(false);
const data = ref<LedgerData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/stock-ledger', { params: { ...range.value, warehouse: warehouse.value || undefined, q: q.value || undefined } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    warehouses.value = (await api.get('/warehouses')).data.data;
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch([range, warehouse], load, { deep: true });

const inCols: Col[] = ['purchase_in', 'return_in', 'transfer_in', 'adjust_in'];
const outCols: Col[] = ['sale_out', 'supplier_out', 'damage_out', 'adjust_out', 'transfer_out'];

function exportCsv() {
    if (!data.value) return;
    const cols = [...inCols, ...outCols];
    downloadCsv(
        `xuat-nhap-ton-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            ['SKU', 'Tên', 'Tồn đầu', ...cols.map((c) => COLUMNS[c]), 'Tổng nhập', 'Tổng xuất', 'Tồn cuối', 'Giá vốn', 'Giá trị tồn cuối'],
            data.value.rows.map((r) => [r.variant.sku, r.variant.name, r.opening, ...cols.map((c) => r[c]), r.in, r.out, r.closing, r.unit_cost, r.closing_value]),
        ),
    );
}
const n = (v: number) => (v ? formatNumber(v) : '—');

const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
const t = (k: string) => Number(data.value?.totals[k] ?? 0);
/** Bar of a row: grey is what is left of the opening stock, green what came in and is still there. */
const scale = computed(() => Math.max(1, ...(data.value?.rows ?? []).map((r) => Math.max(r.opening + r.in, r.closing))));
function bar(r: Row) {
    const kept = Math.max(0, Math.min(r.closing, r.opening - r.out));
    const added = Math.max(0, r.closing - kept);
    return { kept: (kept / scale.value) * 100, added: (added / scale.value) * 100 };
}
const TINTS = ['bg-slate-100 text-slate-600', 'bg-blue-50 text-blue-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600', 'bg-emerald-50 text-emerald-600'];
const tint = (id: string) => TINTS[[...id].reduce((s, c) => s + c.charCodeAt(0), 0) % TINTS.length];

const pageSize = ref(20);
const page = ref(1);
watch(() => [data.value, pageSize.value], () => (page.value = 1));
const pages = computed(() => Math.max(1, Math.ceil((data.value?.rows.length ?? 0) / pageSize.value)));
const shown = computed(() => (data.value?.rows ?? []).slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
const pageList = computed<(number | '…')[]>(() => {
    const n = pages.value;
    const p = page.value;
    if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
    const set = [...new Set([1, 2, p - 1, p, p + 1, n - 1, n].filter((x) => x >= 1 && x <= n))].sort((a, b) => a - b);
    return set.flatMap((x, i) => (i && x - set[i - 1]! > 1 ? (['…', x] as const) : [x]));
});
</script>

<template>
    <div class="mx-auto max-w-[1440px] pt-2">
        <ReportDetailHead
            title="Xuất nhập tồn"
            description="Tồn đầu kỳ, nhập, xuất và tồn cuối kỳ của từng sản phẩm, lấy trực tiếp từ sổ kho."
            :from="range.from"
            :to="range.to"
            :can-export="Boolean(data?.rows.length)"
            @update:range="(r) => (range = r)"
            @export="exportCsv"
        />

        <div v-if="data" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="ledger-kpis">
            <ReportKpi label="Tồn đầu kỳ" :icon="Boxes" tone="slate" :value="t('opening')" unit="sp">
                <template #chips><ReportChip>{{ formatDate(range.from) }}</ReportChip></template>
                {{ formatNumber(data.rows.length) }} SKU có tồn hoặc phát sinh
            </ReportKpi>
            <ReportKpi label="Nhập trong kỳ" :icon="ArrowDownToLine" tone="green" :value="t('in')" :text="t('in') ? `+${formatNumber(t('in'))}` : '0'" unit="sp">
                <template #chips>
                    <ReportChip v-if="t('purchase_in')" tone="up">Nhập mua {{ formatNumber(t('purchase_in')) }}</ReportChip>
                    <ReportChip v-if="t('return_in')">Khách hoàn {{ formatNumber(t('return_in')) }}</ReportChip>
                </template>
            </ReportKpi>
            <ReportKpi label="Xuất trong kỳ" :icon="ArrowUpFromLine" tone="red" :value="t('out')" :text="t('out') ? `−${formatNumber(t('out'))}` : '0'" unit="sp">
                <template #chips>
                    <ReportChip v-if="t('sale_out')" tone="red">Bán {{ formatNumber(t('sale_out')) }}</ReportChip>
                    <ReportChip v-if="t('damage_out') + t('adjust_out')">Hủy, kiểm {{ formatNumber(t('damage_out') + t('adjust_out')) }}</ReportChip>
                </template>
            </ReportKpi>
            <ReportKpi label="Tồn cuối kỳ" :icon="WarehouseIcon" primary :value="t('closing')" unit="sp">
                <template #chips><ReportChip tone="onblue">{{ t('closing') >= t('opening') ? '+' : '−' }}{{ formatNumber(Math.abs(t('closing') - t('opening'))) }} so với đầu kỳ</ReportChip></template>
                Giá trị {{ formatNumber(Math.round(t('closing_value'))) }} đ
            </ReportKpi>
        </div>
        <div v-else-if="!error" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[132px] rounded-[14px]" /></div>

        <section :class="cn(card, 'overflow-hidden')">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="relative w-full sm:w-80">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="q" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" placeholder="Tìm SKU, tên sản phẩm" aria-label="Tìm sản phẩm" />
                </div>
                <Select v-if="warehouses.length > 1" v-model="warehouse" class="w-44" :options="[{ value: '', label: 'Mọi kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" aria-label="Kho" />
                <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Kiểu bảng">
                    <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5', !detail ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500')" @click="detail = false">Gọn</button>
                    <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5', detail ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500')" data-testid="ledger-detail" @click="detail = true">Chi tiết loại nhập, xuất</button>
                </div>
                <div v-if="!detail" class="ml-auto flex items-center gap-4 text-[13px] text-slate-500">
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-slate-400" /> Còn lại từ đầu kỳ</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-emerald-500" /> Nhập thêm</span>
                </div>
            </div>
            <ErrorState v-if="error" :message="error" @retry="load" />
            <div v-else-if="!data" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
            <EmptyState v-else-if="!data.rows.length" :icon="Boxes" title="Không có biến động" description="Không có SKU nào có tồn hoặc phát sinh nhập xuất trong kỳ này." />
            <template v-else>
                <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                    <table class="w-full text-[14px]" data-testid="ledger-table">
                        <thead class="border-b border-slate-100 text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                            <tr class="text-right">
                                <th class="px-5 py-3.5 text-left">Sản phẩm</th>
                                <th class="px-3 py-3.5">Tồn đầu</th>
                                <template v-if="detail"><th v-for="c in inCols" :key="c" class="whitespace-nowrap px-3 py-3.5 text-emerald-600">{{ COLUMNS[c] }}</th></template>
                                <th class="px-3 py-3.5">Nhập</th>
                                <template v-if="detail"><th v-for="c in outCols" :key="c" class="whitespace-nowrap px-3 py-3.5 text-red-500">{{ COLUMNS[c] }}</th></template>
                                <th class="px-3 py-3.5">Xuất</th>
                                <th class="px-3 py-3.5">Tồn cuối</th>
                                <th v-if="!detail" class="w-52 px-4 py-3.5 text-left">Biến động</th>
                                <th class="px-5 py-3.5">Giá trị tồn</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="r in shown" :key="r.variant.id" class="text-right tabular-nums hover:bg-slate-50/70">
                                <td class="min-w-64 px-5 py-3.5 text-left">
                                    <RouterLink :to="`/inventory/sku/${r.variant.id}`" class="flex items-center gap-3">
                                        <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[10px]', tint(r.variant.id))"><Package class="size-5" :stroke-width="1.8" /></span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-slate-900 hover:text-blue-700">{{ r.variant.name }}</span>
                                            <span class="block font-mono text-[12px] text-slate-400">{{ r.variant.sku }}</span>
                                        </span>
                                    </RouterLink>
                                </td>
                                <td class="px-3 py-3.5">{{ formatNumber(r.opening) }}</td>
                                <template v-if="detail"><td v-for="c in inCols" :key="c" class="px-3 py-3.5 text-slate-500">{{ n(r[c]) }}</td></template>
                                <td :class="cn('px-3 py-3.5', r.in ? 'font-medium text-emerald-600' : 'text-slate-300')">{{ r.in ? '+' + formatNumber(r.in) : '—' }}</td>
                                <template v-if="detail"><td v-for="c in outCols" :key="c" class="px-3 py-3.5 text-slate-500">{{ n(r[c]) }}</td></template>
                                <td :class="cn('px-3 py-3.5', r.out ? 'font-medium text-red-600' : 'text-slate-300')">{{ r.out ? '−' + formatNumber(r.out) : '—' }}</td>
                                <td :class="cn('px-3 py-3.5 font-semibold text-slate-900', r.closing < 0 && 'text-red-600')">{{ formatNumber(r.closing) }}</td>
                                <td v-if="!detail" class="px-4 py-3.5">
                                    <div class="flex h-2 w-48 overflow-hidden rounded-full bg-slate-100">
                                        <span class="h-full bg-slate-400" :style="{ width: `${bar(r).kept}%` }" />
                                        <span class="h-full bg-emerald-500" :style="{ width: `${bar(r).added}%` }" />
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-slate-900">{{ formatNumber(Math.round(r.closing_value)) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900">
                            <tr class="text-right tabular-nums">
                                <td class="px-5 py-4 text-left">Tổng {{ formatNumber(data.rows.length) }} SKU</td>
                                <td class="px-3 py-4">{{ formatNumber(data.totals.opening) }}</td>
                                <template v-if="detail"><td v-for="c in inCols" :key="c" class="px-3 py-4">{{ n(data.totals[c] ?? 0) }}</td></template>
                                <td class="px-3 py-4 text-emerald-600">+{{ formatNumber(data.totals.in) }}</td>
                                <template v-if="detail"><td v-for="c in outCols" :key="c" class="px-3 py-4">{{ n(data.totals[c] ?? 0) }}</td></template>
                                <td class="px-3 py-4 text-red-600">−{{ formatNumber(data.totals.out) }}</td>
                                <td class="px-3 py-4">{{ formatNumber(data.totals.closing) }}</td>
                                <td v-if="!detail" />
                                <td class="whitespace-nowrap px-5 py-4">{{ formatNumber(Math.round(data.totals.closing_value)) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-[14px] text-slate-500">
                    <span>Hiển thị {{ formatNumber((page - 1) * pageSize + 1) }}–{{ formatNumber(Math.min(page * pageSize, data.rows.length)) }} trong {{ formatNumber(data.rows.length) }} SKU · bấm một dòng để mở sổ kho của SKU</span>
                    <div class="flex items-center gap-1.5">
                        <template v-if="pages > 1">
                            <button type="button" class="grid size-9 place-items-center rounded-[9px] hover:bg-slate-100 disabled:opacity-40" :disabled="page === 1" aria-label="Trang trước" @click="page--"><ChevronLeft class="size-4" /></button>
                            <template v-for="(p, i) in pageList" :key="i">
                                <span v-if="p === '…'" class="px-1">…</span>
                                <button v-else type="button" :class="cn('grid h-9 min-w-9 place-items-center rounded-[9px] px-2 tabular-nums', p === page ? 'bg-blue-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100')" @click="page = p">{{ p }}</button>
                            </template>
                            <button type="button" class="grid size-9 place-items-center rounded-[9px] hover:bg-slate-100 disabled:opacity-40" :disabled="page === pages" aria-label="Trang sau" @click="page++"><ChevronRight class="size-4" /></button>
                        </template>
                        <select v-model.number="pageSize" class="ml-2 h-10 rounded-[10px] border border-slate-200 bg-white px-3 text-[14px] text-slate-700" aria-label="Số dòng mỗi trang">
                            <option :value="20">20 dòng</option>
                            <option :value="50">50 dòng</option>
                            <option :value="100">100 dòng</option>
                        </select>
                    </div>
                </div>
                <p class="border-t border-slate-100 px-5 py-3 text-[13px] text-slate-500">Giá trị tồn tính theo giá vốn bình quân hiện tại. "Hủy / mất" gồm phiếu điều chỉnh lý do Hàng hỏng và Thất lạc đã duyệt.</p>
            </template>
        </section>
    </div>
</template>
