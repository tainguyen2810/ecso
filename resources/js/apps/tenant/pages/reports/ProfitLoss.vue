<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useElementWidth } from '@/shared/composables/useElementWidth';
import { RouterLink } from 'vue-router';
import { ArrowDown, ArrowUp, BarChart3, ChevronDown, ChevronRight, Info, PiggyBank, Receipt, TrendingUp } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';

/**
 * Báo cáo lãi lỗ (mẫu Tailwind 28/11): four headline figures, a waterfall from revenue to profit, then the P&L table
 * beside the previous period of the same length, groups folding open and shut.
 */
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

interface Line {
    key: string;
    label: string;
    level: number;
    report: string | null;
    current: number;
    previous: number;
    change: number | null;
}
interface PlData {
    range: { from: string; to: string };
    previous_range: { from: string; to: string };
    lines: Line[];
    missing_cost_lines: number;
    fees_unknown_orders?: number;
}

/** Lines whose increase is bad news: the change badge is red when they go up. */
const COSTS = ['discount', 'sales_tax', 'returns', 'cogs', 'cogs_out', 'marketplace_fees', 'fees_confirmed', 'fees_estimated', 'selling_expenses', 'stock_loss', 'write_off', 'stocktake', 'operating_expenses', 'misc_expenses', 'other_expenses'];
const DRILL: Record<string, { name: string; params?: Record<string, string>; query?: Record<string, string> }> = {
    sales: { name: 'report-sales' },
    products: { name: 'report-detail', params: { report: 'product-sales' } },
    returns: { name: 'report-detail', params: { report: 'returns' } },
    stock: { name: 'report-stock' },
    cash: { name: 'cash' },
    finance: { name: 'finance' },
};

const { range } = useReportQuery();
const data = ref<PlData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/profit-loss', { params: range.value })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to], load);

const dmy = (iso: string) => iso.split('-').reverse().join('/');
function changeGood(l: Line): boolean | null {
    if (l.change === null || l.change === 0) return null;
    return COSTS.includes(l.key) ? l.change < 0 : l.change > 0;
}
function drill(l: Line) {
    const d = l.report ? DRILL[l.report] : undefined;
    return d ? { ...d, query: { ...range.value, ...d.query } } : null;
}
function exportCsv() {
    if (!data.value) return;
    const d = data.value;
    downloadCsv(
        `lai-lo-${d.range.from}-${d.range.to}.csv`,
        toCsv(
            ['Chỉ tiêu', `Kỳ trước (${dmy(d.previous_range.from)} - ${dmy(d.previous_range.to)})`, `Kỳ này (${dmy(d.range.from)} - ${dmy(d.range.to)})`, '% thay đổi'],
            d.lines.map((l) => [l.label, l.previous, l.current, l.change]),
        ),
    );
}

const val = (key: string, prev = false) => {
    const l = data.value?.lines.find((x) => x.key === key);
    return l ? Number(prev ? l.previous : l.current) : 0;
};
const line = (key: string) => data.value?.lines.find((x) => x.key === key);
const pct = (a: number, b: number) => (b ? Math.round((a / b) * 1000) / 10 : null);
const vn = (n: number | null) => (n === null ? '—' : n.toLocaleString('vi-VN'));
const costs = computed(() => val('marketplace_fees') + val('selling_expenses') + val('operating_expenses') + val('misc_expenses'));
const short = (v: number) => {
    const a = Math.abs(v);
    const s = a >= 1e9 ? `${(a / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ` : a >= 1e6 ? `${(a / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tr` : a >= 1e3 ? `${Math.round(a / 1e3).toLocaleString('vi-VN')} k` : String(Math.round(a));
    return v < 0 ? `−${s}` : s;
};

/** Waterfall: revenue, then each amount taken off (red) or added (green), ending on the profit. */
const steps = computed(() => {
    if (!data.value) return [];
    const parts: [string, number][] = [
        ['Giá vốn', -val('cogs')],
        ['Phí sàn', -val('marketplace_fees')],
        ['Phí ship thu khách', val('shipping')],
        ['Chi phí bán hàng', -val('selling_expenses')],
        ['Hao hụt kho', -val('stock_loss')],
        ['Chi phí vận hành', -(val('operating_expenses') + val('misc_expenses'))],
        ['Thu nhập khác', val('other_income')],
    ];
    const out: { label: string; from: number; to: number; kind: 'total' | 'up' | 'down' }[] = [{ label: 'Doanh thu thuần', from: 0, to: val('revenue'), kind: 'total' }];
    let run = val('revenue');
    for (const [label, d] of parts) {
        if (!d) continue;
        out.push({ label, from: run, to: run + d, kind: d < 0 ? 'down' : 'up' });
        run += d;
    }
    out.push({ label: 'Lợi nhuận', from: 0, to: val('profit'), kind: 'total' });
    return out;
});
const chartBox = ref<HTMLElement | null>(null);
const boxWidth = useElementWidth(chartBox, 1000);
const W = computed(() => Math.max(640, boxWidth.value));
const H = 220;
const chart = computed(() => {
    const all = steps.value.flatMap((s) => [s.from, s.to]);
    const hi = Math.max(1, ...all);
    const lo = Math.min(0, ...all);
    const y = (v: number) => 30 + ((hi - v) / (hi - lo)) * H;
    const slot = W.value / Math.max(1, steps.value.length);
    return steps.value.map((s, i) => {
        const top = y(Math.max(s.from, s.to));
        const bottom = y(Math.min(s.from, s.to));
        const color = s.kind === 'down' ? '#f87171' : s.kind === 'up' ? '#34d399' : s.label === 'Lợi nhuận' ? (s.to < 0 ? '#ef4444' : '#10b981') : '#2563eb';
        const w = Math.min(slot * 0.64, 120);
        return { ...s, x: i * slot + (slot - w) / 2, w, top, h: Math.max(3, bottom - top), end: y(s.to), color, amount: s.kind === 'total' ? s.to : s.to - s.from, cx: i * slot + slot / 2 };
    });
});

/** Groups (level 0) fold their lines (level 1); everything starts open. */
const closed = ref<Set<string>>(new Set());
const rows = computed(() => {
    const out: (Line & { parent: string | null; hasChildren: boolean })[] = [];
    let parent: string | null = null;
    const lines = data.value?.lines ?? [];
    lines.forEach((l, i) => {
        if (l.level === 0) parent = l.key;
        out.push({ ...l, parent: l.level === 0 ? null : parent, hasChildren: l.level === 0 && lines[i + 1]?.level === 1 });
    });
    return out.filter((l) => !l.parent || !closed.value.has(l.parent));
});
function toggle(key: string) {
    const s = new Set(closed.value);
    if (s.has(key)) s.delete(key);
    else s.add(key);
    closed.value = s;
}
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
</script>

<template>
    <div class="pt-2">
        <ReportDetailHead
            title="Báo cáo lãi lỗ"
            description="Doanh thu, chi phí và lợi nhuận của kỳ đang xem, đặt cạnh kỳ trước có cùng số ngày."
            :from="range.from"
            :to="range.to"
            :can-export="Boolean(data)"
            @update:range="(r) => (range = r)"
            @export="exportCsv"
        />

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[132px] rounded-[14px]" /></div>
            <Skeleton class="h-80 rounded-[14px]" />
        </div>
        <template v-else>
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="pl-kpis">
                <ReportKpi label="Doanh thu thuần" tip="Tiền hàng trừ chiết khấu, thuế GTGT trong giá và hàng trả lại." :icon="BarChart3" primary :value="Math.round(val('revenue'))">
                    <template v-if="line('revenue')?.change != null" #chips>
                        <ReportChip tone="onblue">{{ line('revenue')!.change! >= 0 ? '↑' : '↓' }} {{ vn(Math.abs(line('revenue')!.change!)) }}%</ReportChip>
                        <span class="text-blue-100">so với kỳ trước</span>
                    </template>
                    Đã trừ chiết khấu và hàng trả
                </ReportKpi>
                <ReportKpi label="Lợi nhuận gộp" :icon="TrendingUp" :tone="val('gross_profit') < 0 ? 'red' : 'green'" :value="Math.round(val('gross_profit'))">
                    <template v-if="pct(val('gross_profit'), val('revenue')) !== null" #chips><ReportChip>Biên {{ vn(pct(val('gross_profit'), val('revenue'))) }}%</ReportChip></template>
                    Doanh thu trừ giá vốn
                </ReportKpi>
                <ReportKpi label="Phí sàn và chi phí" :icon="Receipt" tone="amber" :value="Math.round(costs)">
                    <template v-if="pct(costs, val('revenue')) !== null" #chips><ReportChip tone="amber">{{ vn(pct(costs, val('revenue'))) }}% doanh thu</ReportChip></template>
                    Phí sàn {{ short(val('marketplace_fees')) }} · chi phí {{ short(costs - val('marketplace_fees')) }}
                </ReportKpi>
                <ReportKpi label="Lợi nhuận ước tính" :icon="PiggyBank" :tone="val('profit') < 0 ? 'red' : 'green'" :value="Math.round(val('profit'))">
                    <template #chips>
                        <ReportChip v-if="line('profit')?.change != null" :tone="line('profit')!.change! >= 0 ? 'up' : 'down'">{{ line('profit')!.change! >= 0 ? '↑' : '↓' }} {{ vn(Math.abs(line('profit')!.change!)) }}%</ReportChip>
                        <ReportChip v-if="pct(val('profit'), val('revenue')) !== null">Biên {{ vn(pct(val('profit'), val('revenue'))) }}%</ReportChip>
                    </template>
                    Lợi nhuận quản trị, chưa phải kế toán
                </ReportKpi>
            </div>

            <section :class="cn(card, 'mb-5')" data-testid="pl-waterfall">
                <header class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-[16px] font-semibold text-slate-900">Từ doanh thu đến lợi nhuận</h3>
                    <p class="mt-0.5 text-[13px] text-slate-500">Cột đỏ là khoản bị trừ đi, cột xanh lá là khoản cộng thêm.</p>
                </header>
                <div ref="chartBox" class="overflow-x-auto px-4 py-5">
                    <svg :viewBox="`0 0 ${W} ${H + 70}`" :width="W" :height="H + 70" class="block" role="img" aria-label="Từ doanh thu đến lợi nhuận">
                        <g v-for="(b, i) in chart" :key="b.label">
                            <line v-if="i < chart.length - 1" :x1="b.x + b.w" :x2="chart[i + 1]!.x" :y1="b.end" :y2="b.end" stroke="#cbd5e1" stroke-dasharray="3 3" />
                            <rect :x="b.x" :y="b.top" :width="b.w" :height="b.h" rx="4" :fill="b.color" />
                            <text :x="b.cx" :y="b.top - 8" text-anchor="middle" class="fill-slate-800 text-[13px] font-semibold">{{ short(b.amount) }}</text>
                            <text :x="b.cx" :y="H + 58" text-anchor="middle" class="fill-slate-500 text-[13px]">{{ b.label }}</text>
                        </g>
                    </svg>
                </div>
            </section>

            <section :class="cn(card, 'overflow-hidden')">
                <header class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div>
                        <h3 class="text-[16px] font-semibold text-slate-900">Bảng lãi lỗ</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Bấm dòng có mũi tên để xem chi tiết các đơn, phiếu chi.</p>
                    </div>
                    <p class="text-[13px] text-slate-500">Kỳ trước: {{ dmy(data.previous_range.from) }} – {{ dmy(data.previous_range.to) }}</p>
                </header>
                <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                    <table class="w-full text-[14px]" data-testid="pl-table">
                        <thead class="border-b border-slate-100 text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-6 py-3.5 text-left">Chỉ tiêu</th>
                                <th class="px-4 py-3.5 text-right">Kỳ trước</th>
                                <th class="px-4 py-3.5 text-right">Kỳ này</th>
                                <th class="px-6 py-3.5 text-right">Thay đổi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="l in rows"
                                :key="l.key"
                                :class="cn(l.level === 0 && 'bg-slate-50/70 font-semibold text-slate-900', l.level === 1 && 'text-slate-600', l.key === 'profit' && 'bg-emerald-50/70 text-[16px]', l.key === 'contribution_profit' && 'bg-blue-50/50')"
                                :data-testid="`pl-${l.key}`"
                            >
                                <td :class="cn('py-3.5 pr-4', l.level === 1 ? 'pl-14' : 'pl-6')">
                                    <span class="inline-flex items-center gap-2">
                                        <button v-if="l.hasChildren" type="button" class="grid size-5 place-items-center rounded text-slate-400 hover:bg-slate-200" :aria-label="closed.has(l.key) ? 'Mở' : 'Thu gọn'" @click="toggle(l.key)">
                                            <ChevronRight v-if="closed.has(l.key)" class="size-4" /><ChevronDown v-else class="size-4" />
                                        </button>
                                        <span v-else-if="l.level === 0" class="w-5" />
                                        <RouterLink v-if="drill(l)" :to="drill(l)!" class="inline-flex items-center gap-1 hover:text-blue-700 hover:underline">{{ l.label }} <ChevronRight class="size-3.5 text-slate-400" /></RouterLink>
                                        <template v-else>{{ l.label }}</template>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right tabular-nums text-slate-400">{{ formatNumber(Math.round(l.previous)) }}</td>
                                <td
                                    :class="
                                        cn(
                                            'whitespace-nowrap px-4 py-3.5 text-right tabular-nums',
                                            l.current < 0 && 'text-red-600',
                                            l.level === 1 && COSTS.includes(l.key) && l.current > 0 && 'text-red-500',
                                            l.key === 'profit' && (l.current < 0 ? 'text-[18px] font-bold text-red-600' : 'text-[18px] font-bold text-emerald-600'),
                                        )
                                    "
                                >
                                    {{ formatNumber(Math.round(l.current)) }}<span v-if="l.key === 'profit'" class="ml-1 text-[14px]">đ</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right">
                                    <span v-if="changeGood(l) === null" class="text-slate-300">{{ l.change === 0 ? '0%' : '—' }}</span>
                                    <span v-else :class="cn('inline-flex items-center gap-0.5 rounded-md px-2 py-0.5 text-[13px] font-medium tabular-nums', changeGood(l) ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600')">
                                        <ArrowUp v-if="l.change! > 0" class="size-3.5" /><ArrowDown v-else class="size-3.5" />{{ vn(Math.abs(l.change!)) }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="mt-5 flex items-start gap-2 text-[13px] text-slate-500">
                <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                <span>
                    Doanh thu ghi nhận khi sàn báo đơn hoàn thành (đơn tự tạo, website, POS: khi giao thành công). Phí sàn đã xác nhận lấy từ file đối soát, phần còn lại ước tính theo bảng phí của shop.
                    Lợi nhuận quản trị là số ước tính để quản lý, chưa phải lợi nhuận kế toán.
                    <template v-if="data.fees_unknown_orders"> <strong class="text-amber-700">{{ formatNumber(data.fees_unknown_orders) }} đơn chưa biết phí sàn</strong> (shop chưa có bảng phí): lợi nhuận đang cao hơn thực tế.</template>
                    <template v-if="data.missing_cost_lines"> {{ formatNumber(data.missing_cost_lines) }} dòng hàng đã xuất chưa có giá vốn, lợi nhuận đang cao hơn thực tế.</template>
                </span>
            </p>
        </template>
    </div>
</template>
