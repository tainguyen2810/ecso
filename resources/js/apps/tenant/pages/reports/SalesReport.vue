<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Banknote, Info, Package, Receipt, ShoppingCart, TrendingUp, Warehouse } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import SideCard from './SideCard.vue';
import ChannelMark from './ChannelMark.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';

/**
 * Doanh thu theo ngày / kênh / nhân viên / khách / kho (mẫu Tailwind 28/11): one row per entry with its figures and a
 * bar split into giá vốn, phí sàn and lãi; the share of revenue in a donut beside it.
 */
import { channelTypes, marketplaceNames } from '../orders/labels';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

interface Row {
    label: string;
    kind: string | null;
    orders: number;
    units: number;
    revenue: number;
    goods: number;
    // Left out by the server without the "xem giá vốn & lợi nhuận" permission.
    cogs?: number;
    gross_profit?: number;
    margin?: number | null;
    missing_cost_lines?: number;
    shipping?: number;
    // "Theo kênh" only (Đợt 11): marketplace fees and the profit left after them.
    fees?: number;
    fees_confirmed_orders?: number;
    fees_unknown_orders?: number;
    profit_after_fees?: number;
    margin_after_fees?: number | null;
}
interface SalesData {
    rows: Row[];
    totals: Row;
    excluded: { orders: number; revenue: number };
    awaiting: { orders: number; goods: number };
    profit_hidden: boolean;
}

const { range, param } = useReportQuery();
type By = 'day' | 'channel' | 'staff' | 'customer' | 'warehouse';
const TABS: Record<By, string> = { day: 'Theo ngày', channel: 'Theo kênh', staff: 'Theo nhân viên', customer: 'Theo khách hàng', warehouse: 'Theo kho' };
const byParam = param('by', 'day');
const by = computed<By>({ get: () => (byParam.value in TABS ? (byParam.value as By) : 'day'), set: (v) => (byParam.value = v) });
const data = ref<SalesData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/sales', { params: { ...range.value, by: by.value } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to, by.value], load);

const canProfit = computed(() => !data.value?.profit_hidden);
/** Phí sàn and Lãi sau phí sàn columns: the channel tab, for members who see profit. */
const showFees = computed(() => by.value === 'channel' && canProfit.value && data.value?.totals.fees !== undefined);
const maxRevenue = computed(() => Math.max(1, ...(data.value?.rows.map((r) => r.revenue) ?? [1])));
const firstColumn = computed(() => ({ day: 'Ngày', channel: 'Kênh bán', staff: 'Nhân viên', customer: 'Khách hàng', warehouse: 'Kho' })[by.value]);

function label(r: Row): string {
    if (by.value === 'day') {
        const [y, m, d] = r.label.split('-');
        return `${d}/${m}/${y}`;
    }
    return r.label;
}
function kindLabel(kind: string | null): string | null {
    if (!kind) return null;
    return marketplaceNames[kind] ?? channelTypes[kind] ?? kind;
}

function exportCsv() {
    if (!data.value) return;
    const profit = canProfit.value;
    downloadCsv(
        `ban-hang-${by.value}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            [firstColumn.value, 'Số đơn', 'Số lượng', 'Doanh thu', 'Tiền hàng', ...(profit ? ['Giá vốn', 'Lợi nhuận gộp', 'Biên LN %'] : []), ...(showFees.value ? ['Phí sàn', 'Phí giao khách trả', 'Lãi sau phí sàn', 'Biên sau phí %'] : [])],
            data.value.rows.map((r) => [
                label(r), r.orders, r.units, r.revenue, r.goods,
                ...(profit ? [r.cogs ?? 0, r.gross_profit ?? 0, r.margin ?? null] : []),
                ...(showFees.value ? [r.fees ?? 0, r.shipping ?? 0, r.profit_after_fees ?? 0, r.margin_after_fees ?? null] : []),
            ]),
        ),
    );
}

const TITLES: Record<By, string> = {
    day: 'Doanh thu theo ngày',
    channel: 'Doanh thu theo kênh bán',
    staff: 'Doanh thu theo nhân viên',
    customer: 'Doanh thu, lợi nhuận theo khách',
    warehouse: 'Doanh thu theo kho xuất',
};
const profitOf = (r: Row) => (showFees.value ? (r.profit_after_fees ?? 0) : (r.gross_profit ?? 0));
const marginOf = (r: Row) => (showFees.value ? r.margin_after_fees : r.margin);
/** Bar of a row: its length is the revenue against the biggest row, split into cost, fees and what is left. */
function bar(r: Row) {
    const rev = Math.max(0, r.revenue);
    const cogs = canProfit.value ? Math.min(rev, r.cogs ?? 0) : 0;
    const fees = showFees.value ? Math.min(rev - cogs, r.fees ?? 0) : 0;
    const pct = (v: number) => (rev > 0 ? (v / rev) * 100 : 0);
    return { width: (rev / maxRevenue.value) * 100, cogs: pct(cogs), fees: pct(fees), rest: canProfit.value ? Math.max(0, 100 - pct(cogs) - pct(fees)) : 100 };
}
function sub(r: Row): string {
    return [kindLabel(r.kind), r.orders ? `${formatNumber(r.orders)} đơn` : 'Chưa có đơn', r.units ? `${formatNumber(r.units)} SP` : null].filter(Boolean).join(' · ');
}
function initials(name: string): string {
    const w = name.trim().split(/\s+/).filter(Boolean);
    return ((w.length > 1 ? w[w.length - 2]![0]! + w[w.length - 1]![0]! : (w[0] ?? '?').slice(0, 2)) || '?').toUpperCase();
}
const weekday = (iso: string) => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][new Date(`${iso}T00:00:00`).getDay()];

/** Donut of revenue: the five biggest entries, the rest together. */
const COLORS = ['#f97316', '#0f172a', '#2563eb', '#8b5cf6', '#10b981', '#cbd5e1'];
const share = computed(() => {
    const rows = [...(data.value?.rows ?? [])].filter((r) => r.revenue > 0).sort((a, b) => b.revenue - a.revenue);
    const top = rows.slice(0, 5).map((r) => ({ label: label(r), value: r.revenue }));
    const rest = rows.slice(5).reduce((s, r) => s + r.revenue, 0);
    const items = rest > 0 ? [...top, { label: 'Khác', value: rest }] : top;
    const total = items.reduce((s, i) => s + i.value, 0) || 1;
    let offset = 0;
    return items.map((i, n) => {
        const pct = (i.value / total) * 100;
        const seg = { ...i, pct, offset, color: COLORS[n]! };
        offset += pct;
        return seg;
    });
});
const shortMoney = (v: number) => (v >= 1e9 ? `${(v / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ` : v >= 1e6 ? `${(v / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tr` : formatNumber(Math.round(v)));
const best = computed(() => (data.value && data.value.rows.length > 1 ? [...data.value.rows].filter((r) => r.orders).sort((a, b) => profitOf(b) - profitOf(a))[0] : undefined));
const thing = computed(() => ({ day: 'Ngày', channel: 'Kênh', staff: 'Nhân viên', customer: 'Khách', warehouse: 'Kho' })[by.value]);
</script>

<template>
    <div class="pt-2">
        <ReportDetailHead
            :title="TITLES[by]"
            description="Doanh thu đã ghi nhận, giá vốn, phí sàn và lãi. Cùng một khung cho theo ngày, kênh, nhân viên, khách hàng và kho."
            :from="range.from"
            :to="range.to"
            :can-export="Boolean(data?.rows.length)"
            @update:range="(r) => (range = r)"
            @export="exportCsv"
        />

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[132px] rounded-[14px]" /></div>
        <template v-else>
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="sales-kpis">
                <ReportKpi label="Doanh thu" tip="Gồm phí giao khách trả, các đơn đã ghi nhận doanh thu trong kỳ." :icon="Banknote" primary :value="Math.round(data.totals.revenue)">
                    <template #chips><ReportChip tone="onblue">Tiền hàng {{ formatNumber(Math.round(data.totals.goods)) }} đ</ReportChip></template>
                    {{ formatNumber(data.totals.orders) }} đơn · {{ formatNumber(data.totals.units) }} sản phẩm
                </ReportKpi>
                <ReportKpi v-if="canProfit" label="Giá vốn" :icon="Package" tone="slate" :value="Math.round(data.totals.cogs ?? 0)">
                    <template v-if="data.totals.revenue" #chips><ReportChip>{{ Math.round(((data.totals.cogs ?? 0) / data.totals.revenue) * 100) }}% doanh thu</ReportChip></template>
                    Theo giá vốn lúc xuất kho
                </ReportKpi>
                <ReportKpi v-else label="Đơn hàng" :icon="ShoppingCart" tone="violet" :value="data.totals.orders" unit="đơn">{{ formatNumber(data.totals.units) }} sản phẩm</ReportKpi>
                <ReportKpi v-if="showFees" label="Phí sàn" :icon="Receipt" tone="amber" :value="Math.round(data.totals.fees ?? 0)">Theo đối soát, đơn chưa đối soát thì ước tính</ReportKpi>
                <ReportKpi v-else label="Đơn hàng" :icon="ShoppingCart" tone="violet" :value="data.totals.orders" unit="đơn">
                    Trung bình {{ formatNumber(data.totals.orders ? Math.round(data.totals.revenue / data.totals.orders) : 0) }} đ/đơn
                </ReportKpi>
                <ReportKpi
                    v-if="canProfit"
                    :label="showFees ? 'Lãi sau phí sàn' : 'Lợi nhuận gộp'"
                    :icon="TrendingUp"
                    :tone="profitOf(data.totals) < 0 ? 'red' : 'green'"
                    :value="Math.round(profitOf(data.totals))"
                >
                    <template v-if="marginOf(data.totals) != null" #chips><ReportChip :tone="profitOf(data.totals) < 0 ? 'down' : 'up'">Biên {{ marginOf(data.totals) }}%</ReportChip></template>
                    <template v-if="best">{{ thing }} lãi nhất: {{ label(best) }}</template>
                </ReportKpi>
            </div>

            <div :class="cn('grid gap-5', by !== 'day' && 'xl:grid-cols-[minmax(0,1fr)_340px]')">
                <div class="min-w-0 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                        <div class="flex flex-wrap gap-1 rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist">
                            <button
                                v-for="(l, k) in TABS"
                                :key="k"
                                type="button"
                                role="tab"
                                :aria-selected="by === k"
                                :class="cn('rounded-[9px] px-3.5 py-1.5', by === k ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                                :data-testid="`sales-by-${k}`"
                                @click="by = k"
                            >
                                {{ l }}
                            </button>
                        </div>
                        <div v-if="canProfit" class="ml-auto flex items-center gap-4 text-[13px] text-slate-500">
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-slate-300" /> Giá vốn</span>
                            <span v-if="showFees" class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-amber-400" /> Phí sàn</span>
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-full bg-blue-600" /> Lãi</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                        <table class="w-full text-[14px]" data-testid="sales-table">
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="r in data.rows" :key="r.label + r.kind" :class="!r.orders && 'opacity-60'">
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                            <div class="flex min-w-[180px] flex-1 items-center gap-3">
                                                <ChannelMark v-if="by === 'channel'" :kind="r.kind" :name="r.label" size="lg" />
                                                <span v-else-if="by === 'day'" class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-center leading-none text-slate-600">
                                                    <span><b class="block text-[14px]">{{ r.label.slice(8, 10) }}</b><span class="text-[10px] uppercase">{{ weekday(r.label) }}</span></span>
                                                </span>
                                                <span v-else-if="by === 'warehouse'" class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><Warehouse class="size-5" /></span>
                                                <span v-else class="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-500">{{ initials(r.label) }}</span>
                                                <div class="min-w-0">
                                                    <p class="truncate font-semibold text-slate-900">{{ label(r) }}</p>
                                                    <p class="text-[13px] text-slate-500">{{ sub(r) }}</p>
                                                </div>
                                            </div>
                                            <dl class="flex flex-wrap gap-x-4 gap-y-1 text-right tabular-nums [&>div]:w-[6.5rem]">
                                                <div><dt class="text-[12px] text-slate-400">Doanh thu</dt><dd class="font-semibold text-slate-900">{{ r.revenue ? formatNumber(Math.round(r.revenue)) : '—' }}</dd></div>
                                                <div v-if="canProfit"><dt class="text-[12px] text-slate-400">Giá vốn</dt><dd class="text-slate-600">{{ r.cogs ? formatNumber(Math.round(r.cogs)) : '—' }}</dd></div>
                                                <div v-if="showFees" data-testid="channel-fees">
                                                    <dt class="text-[12px] text-slate-400">Phí sàn</dt>
                                                    <dd class="text-amber-600">{{ r.fees ? formatNumber(Math.round(r.fees)) : '—' }}</dd>
                                                    <dd v-if="r.fees_unknown_orders" class="text-[11px] text-amber-600">{{ formatNumber(r.fees_unknown_orders) }} đơn chưa có bảng phí</dd>
                                                    <dd v-else-if="r.fees && r.fees_confirmed_orders !== undefined && r.fees_confirmed_orders < r.orders" class="text-[11px] text-slate-400">có phần ước tính</dd>
                                                </div>
                                                <div v-if="canProfit" class="!w-32">
                                                    <dt class="text-[12px] text-slate-400">{{ showFees ? 'Lãi sau phí' : 'Lãi gộp' }}</dt>
                                                    <dd :class="cn('font-semibold', profitOf(r) < 0 ? 'text-red-600' : 'text-emerald-600')">
                                                        {{ r.orders ? formatNumber(Math.round(profitOf(r))) : '—' }}<span v-if="marginOf(r) != null" class="ml-1 text-[12px] font-normal text-slate-400">{{ marginOf(r) }}%</span>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                        <div v-if="r.revenue > 0" class="mt-3 flex h-2 overflow-hidden rounded-full" :style="{ width: `${Math.max(2, bar(r).width)}%` }">
                                            <span class="h-full bg-slate-300" :style="{ width: `${bar(r).cogs}%` }" />
                                            <span class="h-full bg-amber-400" :style="{ width: `${bar(r).fees}%` }" />
                                            <span :class="cn('h-full', profitOf(r) < 0 ? 'bg-red-400' : 'bg-blue-600')" :style="{ width: `${bar(r).rest}%` }" />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-slate-200 bg-slate-50">
                                <tr>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 font-semibold tabular-nums text-slate-900">
                                            <span>Tổng {{ formatNumber(data.totals.orders) }} đơn · {{ formatNumber(data.totals.units) }} SP</span>
                                            <span class="flex flex-wrap gap-x-4 text-right [&>span]:w-[6.5rem] [&>span:last-child]:w-32">
                                                <span>{{ formatNumber(Math.round(data.totals.revenue)) }}</span>
                                                <span v-if="canProfit" class="font-normal text-slate-600">{{ formatNumber(Math.round(data.totals.cogs ?? 0)) }}</span>
                                                <span v-if="showFees" class="text-amber-600">{{ formatNumber(Math.round(data.totals.fees ?? 0)) }}</span>
                                                <span v-if="canProfit" :class="profitOf(data.totals) < 0 ? 'text-red-600' : 'text-emerald-600'">{{ formatNumber(Math.round(profitOf(data.totals))) }}</span>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div v-if="by !== 'day'" class="space-y-5">
                    <SideCard v-if="share.length" title="Tỉ trọng doanh thu" :description="`Theo ${thing.toLowerCase()} trong kỳ`">
                        <div class="px-5 py-5">
                            <div class="relative mx-auto size-44">
                                <svg viewBox="0 0 42 42" class="size-full -rotate-90" role="img" aria-label="Tỉ trọng doanh thu">
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#f1f5f9" stroke-width="5" />
                                    <circle
                                        v-for="s in share"
                                        :key="s.label"
                                        cx="21"
                                        cy="21"
                                        r="15.9155"
                                        fill="none"
                                        :stroke="s.color"
                                        stroke-width="5"
                                        :stroke-dasharray="`${Math.max(0, s.pct - 0.6)} ${100 - Math.max(0, s.pct - 0.6)}`"
                                        :stroke-dashoffset="-s.offset"
                                    />
                                </svg>
                                <div class="absolute inset-0 grid place-items-center text-center">
                                    <div><p class="text-[24px] font-bold leading-none text-slate-900">{{ shortMoney(data.totals.revenue) }}</p><p class="mt-1 text-[12px] text-slate-500">doanh thu</p></div>
                                </div>
                            </div>
                            <ul class="mt-5 space-y-2.5 text-[14px]">
                                <li v-for="s in share" :key="s.label" class="flex items-center gap-2.5">
                                    <i class="size-2.5 shrink-0 rounded-full" :style="{ background: s.color }" />
                                    <span class="min-w-0 flex-1 truncate text-slate-700">{{ s.label }}</span>
                                    <b class="tabular-nums text-slate-900">{{ Math.round(s.pct) }}%</b>
                                </li>
                            </ul>
                        </div>
                    </SideCard>
                </div>
            </div>

            <div class="mt-5 space-y-2 text-[13px] text-slate-500">
                <p class="flex items-start gap-2" data-testid="sales-basis">
                    <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <span>
                        Tính các đơn đã ghi nhận doanh thu trong kỳ, giống trang Tài chính: đơn sàn khi sàn báo hoàn thành, đơn kênh riêng khi giao xong, bán tại quầy khi bán.
                        <template v-if="data.awaiting.orders"><span class="text-amber-700">{{ formatNumber(data.awaiting.orders) }} đơn sàn đã giao nhưng sàn chưa hoàn thành ({{ formatMoney(data.awaiting.goods) }} tiền hàng) chưa được tính.</span></template>
                    </span>
                </p>
                <p v-if="showFees" class="flex items-start gap-2" data-testid="channel-fees-note">
                    <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <span>Lãi sau phí sàn = lợi nhuận gộp − phí sàn + phí giao khách trả, giống "Lợi nhuận đóng góp" ở trang Tài chính của từng shop. Phí sàn lấy theo đối soát của sàn; đơn chưa đối soát thì ước tính theo bảng phí của shop. Kênh bán riêng không có phí sàn.</span>
                </p>
                <p v-if="data.totals.missing_cost_lines || data.excluded.orders" class="flex items-start gap-2">
                    <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <span>
                        <template v-if="data.totals.missing_cost_lines">{{ formatNumber(data.totals.missing_cost_lines) }} dòng hàng chưa có giá vốn (đơn chưa xuất kho hoặc SKU sàn chưa ghép), lợi nhuận của các đơn này đang tính bằng tiền hàng. </template>
                        <template v-if="data.excluded.orders">Không tính {{ formatNumber(data.excluded.orders) }} đơn hủy/hoàn ({{ formatMoney(data.excluded.revenue) }}).</template>
                    </span>
                </p>
            </div>
        </template>
    </div>
</template>
