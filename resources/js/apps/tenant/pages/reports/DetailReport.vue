<script setup lang="ts">
import { computed, nextTick, onMounted, provide, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    Boxes,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    FileBarChart,
    Hash,
    Info,
    PackagePlus,
    Percent,
    Printer,
    Receipt,
    Search,
    ShoppingCart,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    Undo2,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { useTenantStore } from '@/shared/stores/tenant';
import Alert from '@/shared/ui/Alert.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatNumber } from '@/shared/lib/utils';
import type { Warehouse } from '@/shared/types';
import { reports, type BadgeTone, type Column, type ColumnType, type Kpi, type KpiTone } from './catalog';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ChannelMark from './ChannelMark.vue';

/**
 * One frame for the 18 table reports of catalog.ts (mẫu Tailwind 28/11): head with quick periods and the reports of
 * the same menu, 3–5 headline cards, then one card holding the filters, the table, its totals and pages.
 */
provide('soft-cards', true);
const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const report = computed(() => reports[route.params.report as string]!);
const { range, param, today } = useReportQuery();
const by = param('by');
const tab = computed(() => by.value || Object.keys(report.value.tabs ?? { '': '' })[0]!);
const date = param('date', today);
const warehouse = param('warehouse');
const channel = param('channel');
const channels = ref<{ id: string; name: string }[]>([]);
const tableEl = ref<HTMLTableElement | null>(null);
const q = ref(typeof route.query.q === 'string' ? route.query.q : '');

const warehouses = ref<Warehouse[]>([]);
const data = ref<any>(null);
const error = ref<string | null>(null);
const loading = ref(false);

const completedBasis = computed(() => (['orders', 'sales-lines'].includes(report.value.key) && ['completed', 'awaiting'].includes(String(route.query.basis)) ? String(route.query.basis) : undefined));
function params(): Record<string, string | undefined> {
    const r = report.value;
    return {
        by: r.tabs ? tab.value : undefined,
        warehouse: r.warehouse ? warehouse.value || undefined : undefined,
        channel: r.channel ? channel.value || undefined : undefined,
        q: r.search ? q.value || undefined : undefined,
        // Opened from a day of the sales dashboard: the orders whose revenue was recognised that day.
        // From the amber note: delivered orders the marketplace has not completed yet.
        basis: completedBasis.value,
        ...(r.period === 'range' ? range.value : {}),
        ...(r.period === 'date' ? { date: date.value } : {}),
    };
}

async function load() {
    loading.value = true;
    error.value = null;
    const r = report.value;
    try {
        data.value = (await api.get(r.endpoint, { params: params() })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    if (report.value.warehouse) warehouses.value = (await api.get('/warehouses')).data.data;
    if (report.value.channel) channels.value = (await api.get('/sales-channels').catch(() => ({ data: { data: [] } }))).data.data;
});
// A different report or tab has different columns: drop the old rows instead of showing them under new headers.
watch(() => [route.params.report, tab.value], () => (data.value = null));
watch(() => [route.params.report, tab.value, range.value.from, range.value.to, date.value, warehouse.value, channel.value], load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

// Without "xem giá vốn & lợi nhuận" the server leaves cost and profit out (profit_hidden): drop their columns and cards.
const PROFIT_KEYS = ['cogs', 'gross_profit', 'margin', 'profit_after_fees'];
const columns = computed<Column[]>(() =>
    data.value ? report.value.columns(data.value, tab.value).filter((c) => !(data.value.profit_hidden && PROFIT_KEYS.includes(c.key))) : [],
);
const kpis = computed(() => (data.value && report.value.kpis ? report.value.kpis(data.value).filter((k) => k.value !== undefined) : []));
const allRows = computed<any[]>(() => (data.value ? (report.value.rows?.(data.value) ?? data.value.rows) : []));
const hasTotals = computed(() => columns.value.some((c) => c.total));

function raw(c: Column, r: any): string | number | null {
    return c.value ? c.value(r) : r[c.key];
}
function show(type: ColumnType | undefined, v: string | number | null): string {
    if (v === null || v === undefined || v === '') return '—';
    switch (type) {
        case 'money':
            // The header says it is money: cells show the bare figure, like the mockup.
            return formatNumber(Math.round(Number(v)));
        case 'number':
            return formatNumber(v);
        case 'pct':
            return `${Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;
        case 'date':
            return formatDate(String(v));
        case 'datetime': {
            const d = new Date(String(v));
            if (Number.isNaN(d.getTime())) return formatDateTime(String(v));
            const p2 = (n: number) => String(n).padStart(2, '0');
            const year = d.getFullYear() === new Date().getFullYear() ? '' : `/${d.getFullYear()}`;
            return `${p2(d.getDate())}/${p2(d.getMonth() + 1)}${year} · ${p2(d.getHours())}:${p2(d.getMinutes())}`;
        }
        default:
            return String(v);
    }
}
function total(c: Column): string {
    if (!c.total) return '';
    // A channel chip narrows the rows: add up what is shown instead of the server's totals.
    if (filtered.value) return numeric(c) && c.type !== 'pct' ? show(c.type, rows.value.reduce((s, r) => s + Number(raw(c, r) ?? 0), 0)) : '';
    const v = typeof c.total === 'function' ? c.total(data.value) : data.value.totals?.[c.key];
    return show(c.type, v);
}
const numeric = (c: Column) => ['money', 'number', 'pct'].includes(c.type ?? '');
const toneClass = { danger: 'font-medium text-red-600', success: 'font-medium text-emerald-600', muted: 'text-slate-400' } as const;


/** Headline cards: the first leads in blue; colour and icon follow what the figure is unless the report says. */
function kpiTone(k: Kpi, i: number): KpiTone {
    if (k.tone) return k.tone;
    const l = k.label.toLowerCase();
    if (/lỗ|quá hạn|hết hạn|hết hàng|hỏng/.test(l)) return 'red';
    if (/nợ|giữ|dư|chiết khấu|≤|vượt|chưa rõ/.test(l)) return 'amber';
    if (/thiếu|chi(?=\s|$)|giảm|hoàn/.test(l)) return 'red';
    if (/lãi|thu(?=\s|$)|mới|tăng|có thể/.test(l)) return 'green';
    return (['blue', 'violet', 'slate', 'green'] as const)[i % 4]!;
}
function kpiIcon(k: Kpi): Component {
    const l = k.label.toLowerCase();
    if (/lỗ|giảm/.test(l)) return TrendingDown;
    if (/lãi|tăng/.test(l)) return TrendingUp;
    if (/quá hạn|hết hạn|ngày/.test(l)) return CalendarClock;
    if (/hết hàng|thiếu|hỏng|vượt|dư/.test(l)) return CircleAlert;
    if (/phải trả|phải thu/.test(l)) return Wallet;
    if (/trả hàng|hàng trả|hoàn|số lượng trả|trả ncc/.test(l)) return Undo2;
    if (/khách|nhà cung cấp/.test(l)) return Users;
    if (/thu(?=\s|$)|đã trả|thanh toán/.test(l)) return Wallet;
    if (/chi(?=\s|$)|chênh/.test(l)) return ArrowDownRight;
    if (/đơn/.test(l) && k.type === 'number') return ShoppingCart;
    if (/phiếu/.test(l)) return Receipt;
    if (/chiết khấu|biên/.test(l)) return Percent;
    if (/sku|tồn|lô|số lượng|sp(?=\s|$)|sản phẩm/.test(l)) return Boxes;
    if (k.type === 'money') return Banknote;
    if (k.type === 'number') return Hash;
    return ArrowUpRight;
}
function kpiFigure(k: Kpi): { value: number | null; unit: string; text?: string } {
    if (typeof k.value === 'string') return { value: null, unit: '', text: k.value };
    if (k.type === 'money') return { value: Math.round(Number(k.value)), unit: 'đ' };
    if (k.type === 'pct') return { value: null, unit: '', text: show('pct', k.value) };
    return { value: Number(k.value), unit: '' };
}
const kpiCols = computed(() => ({ 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-2 xl:grid-cols-4', 5: 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5' })[Math.min(kpis.value.length, 5)]);

/** Orders and sale lines: one chip per channel, counted on the rows shown (only when they are all there). */
const logoCol = computed(() => columns.value.find((c) => c.logo));
const chip = ref('');
watch(() => [route.params.report, tab.value, data.value], () => (chip.value = ''));
const chips = computed(() => {
    const c = logoCol.value;
    if (!c?.sub || !data.value || data.value.truncated) return [];
    const map = new Map<string, { name: string; kind: string | null; count: number }>();
    for (const r of allRows.value) {
        const name = c.sub(r) ?? '—';
        const e = map.get(name) ?? { name, kind: c.logo!(r), count: 0 };
        e.count++;
        map.set(name, e);
    }
    return map.size > 1 ? [...map.values()].sort((a, b) => b.count - a.count) : [];
});
const rows = computed<any[]>(() => (chip.value && logoCol.value?.sub ? allRows.value.filter((r) => logoCol.value!.sub!(r) === chip.value) : allRows.value));

/** 20 rows a page; printing takes every row. */
const pageSize = ref(20);
const page = ref(1);
const printing = ref(false);
watch(() => [rows.value, pageSize.value], () => (page.value = 1));
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / pageSize.value)));
const shown = computed(() => (printing.value ? rows.value : rows.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value)));
const pageList = computed<(number | '…')[]>(() => {
    const n = pages.value;
    const p = page.value;
    if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
    const set = [...new Set([1, 2, p - 1, p, p + 1, n - 1, n].filter((x) => x >= 1 && x <= n))].sort((a, b) => a - b);
    return set.flatMap((x, i) => (i && x - set[i - 1]! > 1 ? (['…', x] as const) : [x]));
});

const badgeClass: Record<BadgeTone, string> = {
    success: 'bg-emerald-50 text-emerald-700',
    danger: 'bg-red-50 text-red-600',
    warning: 'bg-amber-50 text-amber-700',
    muted: 'bg-slate-100 text-slate-500',
    default: 'bg-blue-50 text-blue-700',
};
const PROFIT_STRONG = ['gross_profit', 'profit_after_fees', 'profit', 'net'];
function cellClass(c: Column, row: any): string | undefined {
    const t = c.tone?.(row);
    if (t) return toneClass[t];
    if (PROFIT_STRONG.includes(c.key) && numeric(c)) return Number(raw(c, row)) < 0 ? 'font-semibold text-red-600' : c.key === 'net' ? 'font-semibold text-slate-900' : 'font-semibold text-emerald-600';
    if (c.key === 'discount' && Number(raw(c, row)) > 0) return 'text-red-500';
    if (c.key === 'fees' && Number(raw(c, row)) > 0) return 'text-amber-600';
    return undefined;
}
function initials(name: string): string {
    const w = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    return ((w.length > 1 ? w[w.length - 2]![0]! + w[w.length - 1]![0]! : (w[0] ?? '?').slice(0, 2)) || '?').toUpperCase();
}
const filtered = computed(() => Boolean(chip.value));

/** "In báo cáo": the whole table (every page), with the title and period, in its own print job (A4 landscape). */
async function printReport() {
    printing.value = true;
    await nextTick();
    if (!tableEl.value) return (printing.value = false);
    const r = report.value;
    const period = r.period === 'range' ? `${formatDate(range.value.from)} – ${formatDate(range.value.to)}` : r.period === 'date' ? `Đến hết ${formatDate(date.value)}` : `Ngày ${formatDate(today)}`;
    const shop = tenant.current?.name ?? '';
    const html = tableEl.value.outerHTML;
    printing.value = false;
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(`<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${r.title}</title><style>
@page { size: A4 landscape; margin: 10mm; } body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
h1 { font-size: 16px; margin: 0; } .muted { color: #444; margin: 2px 0 8px; } table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; } th { background: #eee; } a { color: inherit; text-decoration: none; }
.text-right { text-align: right; } span.block { display: block; color: #555; font-size: 10px; } img, .print-hide { display: none; }
</style></head><body><h1>${r.title}</h1><p class="muted">${shop} · ${period}${r.tabs ? ' · ' + (r.tabs[tab.value] ?? '') : ''}</p>${html}</body></html>`);
    doc.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
        setTimeout(() => frame.remove(), 1000);
    }, 100);
}

/** The table may stop at the row limit; the file then asks the server for every row of the same filter. */
const exporting = ref(false);
async function exportCsv() {
    if (!data.value) return;
    const r = report.value;
    let source = data.value;
    if (data.value.truncated) {
        exporting.value = true;
        try {
            source = (await api.get(r.endpoint, { params: { ...params(), export: '1' } })).data.data;
        } catch (e) {
            error.value = (e as ApiError).message;
            return;
        } finally {
            exporting.value = false;
        }
    }
    const exportRows: any[] = (r.rows?.(source) ?? source.rows).filter((row: any) => !chip.value || logoCol.value?.sub?.(row) === chip.value);
    const suffix = r.period === 'range' ? `${range.value.from}-${range.value.to}` : r.period === 'date' ? date.value : today;
    downloadCsv(
        `${r.key}${r.tabs ? `-${tab.value}` : ''}-${suffix}.csv`,
        toCsv(
            columns.value.flatMap((c) => (c.sub ? [c.label, 'Mã'] : [c.label])),
            exportRows.map((row) => columns.value.flatMap((c) => (c.sub ? [raw(c, row), c.sub(row)] : [raw(c, row)]))),
        ),
    );
}
</script>

<template>
    <div class="mx-auto max-w-[1440px] pt-2">
        <ReportDetailHead
            :title="report.title"
            :description="report.description"
            :period="report.period"
            :from="range.from"
            :to="range.to"
            :date="date"
            :max="today"
            :exporting="exporting"
            :can-export="rows.length > 0"
            :export-label="data?.truncated ? `Xuất báo cáo (${formatNumber(data.total_rows)} dòng)` : 'Xuất báo cáo'"
            @update:range="(r) => (range = r)"
            @update:date="(v) => (date = v)"
            @export="exportCsv"
        >
            <template #actions>
                <button
                    type="button"
                    class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700 disabled:opacity-50"
                    :disabled="!rows.length"
                    data-testid="print-report"
                    @click="printReport"
                >
                    <Printer class="size-4" /> In
                </button>
            </template>
        </ReportDetailHead>

        <div v-if="kpis.length" :class="cn('mb-5 grid gap-4', kpiCols)" data-testid="report-kpis">
            <ReportKpi
                v-for="(k, i) in kpis"
                :key="k.label"
                :label="k.label"
                :icon="kpiIcon(k)"
                :tone="kpiTone(k, i)"
                :primary="i === 0"
                :value="kpiFigure(k).value"
                :text="kpiFigure(k).text"
                :unit="kpiFigure(k).unit"
            >
                <template v-if="k.hint">{{ k.hint }}</template>
            </ReportKpi>
        </div>
        <div v-else-if="!data && !error" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[132px] rounded-[14px]" /></div>

        <Alert v-if="data?.truncated" tone="amber" :icon="TriangleAlert" class="mb-4 rounded-[14px]" data-testid="report-truncated">
            Kỳ này có <b>{{ formatNumber(data.total_rows) }} dòng</b>. Bảng hiển thị <b>{{ formatNumber(allRows.length) }} dòng đầu</b>; dòng <b>Tổng</b> và file xuất tính trên toàn bộ
            {{ formatNumber(data.total_rows) }} dòng. Thu hẹp ngày hoặc thêm bộ lọc để xem hết trên màn hình.
        </Alert>

        <div class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div v-if="report.search" class="relative w-full sm:w-80">
                    <Search class="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="q"
                        :placeholder="report.searchPlaceholder ?? 'Tìm SKU, tên sản phẩm'"
                        class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                        aria-label="Tìm kiếm"
                    />
                </div>
                <div v-if="report.tabs" class="flex flex-wrap gap-1 rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist">
                    <button
                        v-for="(label, k) in report.tabs"
                        :key="k"
                        type="button"
                        role="tab"
                        :aria-selected="tab === k"
                        :class="cn('rounded-[9px] px-3.5 py-1.5', tab === k ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                        :data-testid="`tab-${k}`"
                        @click="by = String(k)"
                    >
                        {{ label }}
                    </button>
                </div>
                <Select
                    v-if="report.channel && channels.length > 1"
                    v-model="channel"
                    class="w-52"
                    aria-label="Kênh bán"
                    :options="[{ value: '', label: 'Mọi kênh bán riêng' }, ...channels.map((c) => ({ value: c.id, label: c.name }))]"
                />
                <Select
                    v-if="report.warehouse && warehouses.length > 1"
                    v-model="warehouse"
                    class="w-48"
                    aria-label="Kho"
                    :options="[{ value: '', label: 'Tất cả kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]"
                />
                <button
                    v-if="report.action && rows.length"
                    type="button"
                    class="ml-auto inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-4 text-[14px] font-medium text-white shadow-sm hover:bg-blue-700"
                    @click="router.push(report.action.to)"
                >
                    <PackagePlus class="size-4" /> {{ report.action.label }}
                </button>
            </div>

            <div v-if="chips.length || report.note" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3">
                <template v-if="chips.length">
                    <button
                        type="button"
                        :class="cn('inline-flex h-9 items-center rounded-[10px] px-3 text-[14px]', !chip ? 'bg-slate-900 font-medium text-white' : 'border border-slate-200 bg-white text-slate-600 hover:border-blue-300')"
                        data-testid="channel-chip-all"
                        @click="chip = ''"
                    >
                        Mọi kênh · {{ formatNumber(allRows.length) }}
                    </button>
                    <button
                        v-for="c in chips"
                        :key="c.name"
                        type="button"
                        :class="cn('inline-flex h-9 items-center gap-2 rounded-[10px] px-3 text-[14px]', chip === c.name ? 'bg-slate-900 font-medium text-white' : 'border border-slate-200 bg-white text-slate-600 hover:border-blue-300')"
                        @click="chip = chip === c.name ? '' : c.name"
                    >
                        <ChannelMark :kind="c.kind" :name="c.name" size="sm" class="!size-5" /> {{ c.name }} · {{ formatNumber(c.count) }}
                    </button>
                </template>
                <p v-if="report.note" class="ml-auto flex items-start gap-1.5 text-[13px] text-slate-500">
                    <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <span v-if="data?.basis === 'completed'" data-testid="report-basis-completed">Đơn đã ghi nhận doanh thu trong kỳ (đơn sàn khi sàn báo hoàn thành, kênh riêng khi giao xong), giống biểu đồ ở Báo cáo bán hàng.</span>
                    <span v-else-if="data?.basis === 'awaiting'" class="text-amber-700" data-testid="report-basis-awaiting">Đơn sàn đã giao trong kỳ nhưng sàn chưa báo hoàn thành: chưa tính vào doanh thu, sẽ được tính khi sàn hoàn thành đơn.</span>
                    <span v-else>{{ report.note }}</span>
                </p>
            </div>

            <ErrorState v-if="error" :message="error" @retry="load" />
            <div v-else-if="!data" class="space-y-3 p-5"><Skeleton v-for="i in 8" :key="i" class="h-8" /></div>
            <EmptyState v-else-if="!rows.length" :icon="FileBarChart" title="Chưa có số liệu" :description="report.empty" />
            <template v-else>
                <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                    <table ref="tableEl" class="w-full text-[14px]" data-testid="report-table">
                        <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                            <tr>
                                <th v-for="c in columns" :key="c.key" :class="cn('whitespace-nowrap px-4 py-3.5 font-semibold first:pl-5 last:pr-5', numeric(c) && 'text-right', c.bar && 'min-w-36')">{{ c.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="(row, i) in shown" :key="i" class="hover:bg-slate-50/70">
                                <td
                                    v-for="c in columns"
                                    :key="c.key"
                                    :class="cn('px-4 py-3.5 first:pl-5 last:pr-5', c === columns[0] && 'min-w-56', c.type === 'datetime' && 'whitespace-nowrap', numeric(c) && 'whitespace-nowrap text-right tabular-nums', cellClass(c, row))"
                                >
                                    <div v-if="c.logo" class="flex items-center gap-3 whitespace-nowrap">
                                        <ChannelMark :kind="c.logo(row)" :name="c.sub?.(row) ?? undefined" />
                                        <div class="min-w-0">
                                            <RouterLink v-if="c.link?.(row)" :to="c.link(row)!" class="font-mono text-[13px] font-medium text-blue-700 hover:underline">{{ show(c.type, raw(c, row)) }}</RouterLink>
                                            <span v-else class="font-mono text-[13px]">{{ show(c.type, raw(c, row)) }}</span>
                                            <span v-if="c.sub?.(row)" class="block text-[13px] text-slate-500">{{ c.sub(row) }}</span>
                                        </div>
                                    </div>
                                    <span v-else-if="c.badge?.(row)" :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[13px] font-medium', badgeClass[c.badge(row)!.tone])">{{ c.badge(row)!.label }}</span>
                                    <div v-else-if="c.bar" class="flex items-center justify-end gap-3">
                                        <span class="print-hide h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                                            <span
                                                :class="cn('block h-full rounded-full', Number(raw(c, row)) < 0 ? 'bg-red-500' : 'bg-emerald-500')"
                                                :style="{ width: `${Math.min(100, Math.max(4, Math.abs(Number(raw(c, row) ?? 0))))}%` }"
                                            />
                                        </span>
                                        <span :class="cn('w-12 text-right', Number(raw(c, row)) < 0 ? 'text-red-600' : 'text-slate-600')">{{ show(c.type, raw(c, row)) }}</span>
                                    </div>
                                    <div v-else-if="c.avatar" class="flex items-center gap-2.5 whitespace-nowrap">
                                        <span class="print-hide grid size-8 shrink-0 place-items-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-500">{{ initials(String(raw(c, row) ?? '')) }}</span>
                                        {{ show(c.type, raw(c, row)) }}
                                    </div>
                                    <template v-else>
                                        <RouterLink v-if="c.link?.(row)" :to="c.link(row)!" class="font-medium text-slate-900 hover:text-blue-700 hover:underline">{{ show(c.type, raw(c, row)) }}</RouterLink>
                                        <template v-else>{{ show(c.type, raw(c, row)) }}</template>
                                        <span v-if="c.sub?.(row)" class="block text-[13px] font-normal text-slate-500">{{ c.sub(row) }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="hasTotals" class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900">
                            <tr>
                                <td v-for="(c, i) in columns" :key="c.key" :class="cn('whitespace-nowrap px-4 py-4 first:pl-5 last:pr-5', numeric(c) && 'text-right tabular-nums', i > 0 && cellClass(c, data.totals ?? {}))">
                                    {{ i === 0 ? (filtered ? `Tổng ${chip}` : data.truncated ? 'Tổng (toàn bộ)' : 'Tổng') : total(c) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-[14px] text-slate-500" data-testid="report-pages">
                    <span>
                        Hiển thị {{ formatNumber((page - 1) * pageSize + 1) }}–{{ formatNumber(Math.min(page * pageSize, rows.length)) }} trong {{ formatNumber(rows.length) }} dòng<template v-if="data.truncated">
                            (đầu tiên của {{ formatNumber(data.total_rows) }})</template
                        >
                    </span>
                    <div class="flex items-center gap-1.5">
                        <template v-if="pages > 1">
                            <button type="button" class="grid size-9 place-items-center rounded-[9px] hover:bg-slate-100 disabled:opacity-40" :disabled="page === 1" aria-label="Trang trước" @click="page--"><ChevronLeft class="size-4" /></button>
                            <template v-for="(p, i) in pageList" :key="i">
                                <span v-if="p === '…'" class="px-1">…</span>
                                <button
                                    v-else
                                    type="button"
                                    :class="cn('grid h-9 min-w-9 place-items-center rounded-[9px] px-2 tabular-nums', p === page ? 'bg-blue-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100')"
                                    @click="page = p"
                                >
                                    {{ p }}
                                </button>
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
            </template>
        </div>
    </div>
</template>
