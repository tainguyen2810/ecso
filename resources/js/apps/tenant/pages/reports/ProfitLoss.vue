<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowDown, ArrowUp, BarChart3, ChevronDown, ChevronRight, Info, PiggyBank, Receipt, TrendingUp } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ChannelMark from './ChannelMark.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';

/**
 * Báo cáo lãi lỗ (mẫu A 28/11): four headline figures, a ladder from revenue to profit with where each 100đ goes and the
 * profit of each channel, then the P&L table
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

/**
 * Bậc thang (mẫu A 28/11): one row per amount, each a bar on the same scale as the revenue. Red bars are taken off,
 * green ones added, bold rows are what is left (gross, contribution, profit).
 */
type Step = { label: string; from: number; to: number; kind: 'total' | 'sub' | 'down' | 'up' | 'end' };
const steps = computed<Step[]>(() => {
    if (!data.value) return [];
    const out: Step[] = [{ label: 'Doanh thu thuần', from: 0, to: val('revenue'), kind: 'total' }];
    let run = val('revenue');
    const add = (label: string, d: number) => {
        if (!d) return;
        out.push({ label, from: run, to: run + d, kind: d < 0 ? 'down' : 'up' });
        run += d;
    };
    const sub = (label: string, key: string, kind: Step['kind'] = 'sub') => {
        run = val(key);
        out.push({ label, from: 0, to: run, kind });
    };
    add('Giá vốn', -val('cogs'));
    sub('Lợi nhuận gộp', 'gross_profit');
    add('Phí sàn', -val('marketplace_fees'));
    add('Phí ship thu khách', val('shipping'));
    add('Chi phí bán hàng', -val('selling_expenses'));
    sub('Lợi nhuận đóng góp', 'contribution_profit');
    add('Hao hụt kho', -val('stock_loss'));
    add('Chi phí vận hành', -val('operating_expenses'));
    add('Chi phí khác', -val('misc_expenses'));
    add('Thu nhập khác', val('other_income'));
    sub('Lợi nhuận ước tính', 'profit', 'end');
    return out;
});
const ladder = computed(() => {
    const all = steps.value.flatMap((s) => [s.from, s.to]);
    const lo = Math.min(0, ...all);
    const span = Math.max(1, ...all) - lo || 1;
    return steps.value.map((s) => {
        const a = Math.min(s.from, s.to);
        const amount = s.kind === 'down' || s.kind === 'up' ? s.to - s.from : s.to;
        return { ...s, amount, left: ((a - lo) / span) * 100, width: Math.max(0.4, (Math.abs(s.to - s.from) / span) * 100), share: pct(Math.abs(amount), val('revenue')) };
    });
});
const barTone = { total: 'bg-blue-600', sub: 'bg-blue-200', down: 'bg-rose-400', up: 'bg-emerald-400', end: 'bg-emerald-500' } as const;

/** "Mỗi 100đ doanh thu đi đâu": only when every part is a cost and the shop made money. */
const per100 = computed(() => {
    const rev = val('revenue');
    if (rev <= 0) return null;
    const parts = [
        { label: 'Giá vốn', value: val('cogs'), tone: 'bg-slate-400' },
        { label: 'Phí sàn', value: val('marketplace_fees'), tone: 'bg-orange-400' },
        { label: 'Chi phí bán hàng', value: val('selling_expenses') - val('shipping'), tone: 'bg-amber-300' },
        { label: 'Vận hành, hao hụt, khác', value: val('stock_loss') + val('operating_expenses') + val('misc_expenses') - val('other_income'), tone: 'bg-rose-300' },
        { label: 'Lãi còn lại', value: val('profit'), tone: 'bg-emerald-500' },
    ];
    if (parts.some((p) => p.value < 0)) return null;
    return parts.filter((p) => p.value > 0).map((p) => ({ ...p, per: Math.round((p.value / rev) * 1000) / 10 }));
});

/** Lãi theo kênh bán: the "Theo kênh" figures of Báo cáo bán hàng (profit after marketplace fees). */
interface ChannelRow { label: string; kind: string | null; goods: number; profit_after_fees?: number; margin_after_fees?: number | null }
const channels = ref<ChannelRow[]>([]);
async function loadChannels() {
    try {
        const d = (await api.get('/reports/sales', { params: { ...range.value, by: 'channel' } })).data.data;
        channels.value = d.profit_hidden ? [] : (d.rows as ChannelRow[]).filter((r) => r.profit_after_fees !== undefined && r.goods > 0).slice(0, 6);
    } catch {
        channels.value = [];
    }
}
onMounted(loadChannels);
watch(() => [range.value.from, range.value.to], loadChannels);

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

            <div class="mb-5 grid items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_420px]">
                <section :class="card" data-testid="pl-waterfall">
                    <header class="border-b border-slate-100 px-6 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Từ doanh thu đến lợi nhuận</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Mỗi dòng là một khoản: thanh đỏ bị trừ đi, thanh xanh lá cộng thêm, dòng đậm là phần còn lại.</p>
                    </header>
                    <div class="px-6 py-3">
                        <div
                            v-for="s in ladder"
                            :key="s.label"
                            :class="cn('grid grid-cols-[150px_minmax(0,1fr)_120px] items-center gap-4 py-2 sm:grid-cols-[190px_minmax(0,1fr)_130px_64px]', (s.kind === 'sub' || s.kind === 'end') && 'mt-1 border-t border-slate-100 pt-3')"
                            :data-testid="`pl-step-${s.kind}`"
                        >
                            <p :class="cn('text-[14px]', s.kind === 'down' || s.kind === 'up' ? 'pl-4 text-slate-600' : 'font-semibold text-slate-900')">{{ s.label }}</p>
                            <div class="relative h-7 rounded-[7px] bg-slate-50">
                                <span :class="cn('absolute inset-y-0 rounded-[7px]', s.kind === 'end' && s.to < 0 ? 'bg-red-500' : barTone[s.kind])" :style="{ left: `${s.left}%`, width: `${s.width}%` }" />
                            </div>
                            <p
                                :class="
                                    cn(
                                        'text-right text-[14px] tabular-nums',
                                        s.kind === 'down' ? 'text-rose-600' : s.kind === 'up' ? 'text-emerald-600' : s.kind === 'end' ? (s.to < 0 ? 'font-semibold text-red-600' : 'font-semibold text-emerald-600') : 'font-semibold text-slate-900',
                                    )
                                "
                            >
                                {{ s.kind === 'down' ? '−' : s.kind === 'up' ? '+' : '' }}{{ formatNumber(Math.round(Math.abs(s.amount))) }}
                            </p>
                            <p class="hidden text-right text-[13px] tabular-nums text-slate-500 sm:block">{{ s.share === null ? '' : `${vn(s.share)}%` }}</p>
                        </div>
                    </div>
                </section>

                <div class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
                    <section v-if="per100" :class="card" data-testid="pl-per100">
                        <header class="border-b border-slate-100 px-5 py-4">
                            <h3 class="font-semibold text-slate-900">Mỗi 100đ doanh thu đi đâu</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">Kỳ này, sau khi trừ mọi khoản</p>
                        </header>
                        <div class="px-5 py-5">
                            <div class="flex h-4 overflow-hidden rounded-full bg-slate-100">
                                <span v-for="p in per100" :key="p.label" :class="p.tone" :style="{ width: `${p.per}%` }" />
                            </div>
                            <ul class="mt-3 divide-y divide-slate-100">
                                <li v-for="p in per100" :key="p.label" class="flex items-center justify-between py-2.5 text-[14px]">
                                    <span class="flex items-center gap-2.5 text-slate-600"><span :class="cn('size-2.5 rounded-full', p.tone)" />{{ p.label }}</span>
                                    <span :class="cn('font-semibold tabular-nums', p.label === 'Lãi còn lại' ? 'text-emerald-600' : 'text-slate-900')">{{ vn(p.per) }}đ</span>
                                </li>
                            </ul>
                        </div>
                    </section>
                    <section v-if="channels.length" :class="card" data-testid="pl-channels">
                        <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                            <div>
                                <h3 class="font-semibold text-slate-900">Lãi theo kênh bán</h3>
                                <p class="mt-0.5 text-[13px] text-slate-500">Sau giá vốn và phí sàn, trước chi phí chung</p>
                            </div>
                            <RouterLink :to="{ name: 'report-sales', query: { ...range, by: 'channel' } }" class="shrink-0 text-[13px] font-medium text-blue-700 hover:underline">Chi tiết</RouterLink>
                        </header>
                        <ul class="divide-y divide-slate-100 px-5">
                            <li v-for="c in channels" :key="c.label" class="flex items-center gap-3 py-3">
                                <ChannelMark :kind="c.kind" :name="c.label" size="lg" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[14px] font-medium text-slate-900">{{ c.label }}</p>
                                    <p class="text-[12px] text-slate-500">Doanh thu {{ short(c.goods) }}</p>
                                </div>
                                <div class="text-right">
                                    <p :class="cn('text-[14px] font-semibold tabular-nums', (c.profit_after_fees ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600')">{{ formatNumber(Math.round(c.profit_after_fees ?? 0)) }}</p>
                                    <p v-if="c.margin_after_fees != null" class="text-[12px] text-slate-500">biên {{ vn(c.margin_after_fees) }}%</p>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

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
                                <th class="hidden px-4 py-3.5 text-right md:table-cell">% doanh thu</th>
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
                                <td class="hidden whitespace-nowrap px-4 py-3.5 text-right text-[13px] tabular-nums text-slate-500 md:table-cell">{{ pct(Math.abs(l.current), val('revenue')) === null ? '' : `${vn(pct(Math.abs(l.current), val('revenue')))}%` }}</td>
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
