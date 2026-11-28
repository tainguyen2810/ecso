<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowRight, ArrowUp, Box, Receipt, CircleCheck, CircleHelp, CreditCard, FileText, Hexagon, ShoppingCart, TrendingUp, TriangleAlert, Truck, Undo2 } from 'lucide-vue-next';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { wholePercents } from '@/shared/ui/charts/percent';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import type { ReportLink } from './groups';
import { downloadCsv, toCsv } from './range';

/**
 * "Báo cáo bán hàng" dashboard (mẫu 28/11): four headline figures, revenue per day, revenue per channel, delivery
 * status, the report links grouped as Đơn hàng / Thanh toán / Trả hàng, and the best sellers. Every figure comes from
 * the same API as before (/reports/overview, /reports/sales, /reports/product-sales).
 */
const props = defineProps<{ range: { from: string; to: string }; links: ReportLink[]; target: (l: ReportLink, by?: string) => RouteLocationRaw }>();

const router = useRouter();
const tenant = useTenantStore();

const overview = ref<any>(null);
const days = ref<any[]>([]);
const awaiting = ref<{ orders: number; goods: number } | null>(null);
const channels = ref<any[]>([]);
const channelTotals = ref<any>(null);
const previous = ref<any>(null);
const products = ref<any[]>([]);
const error = ref<string | null>(null);
const loading = ref(false);

/** The period of the same length just before this one, to compare with. */
function previousRange(from?: string, to?: string): { from: string; to: string } | null {
    if (!from || !to) return null;
    const day = 86_400_000;
    const f = new Date(`${from}T00:00:00`);
    const t = new Date(`${to}T00:00:00`);
    const len = Math.round((t.getTime() - f.getTime()) / day) + 1;
    if (!(len > 0)) return null;
    const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return { from: iso(new Date(f.getTime() - len * day)), to: iso(new Date(f.getTime() - day)) };
}
/** Change against the previous period in %, null when there is nothing to compare with. */
function change(now: number | null | undefined, before: number | null | undefined): number | null {
    if (now == null || before == null || !before) return null;
    return ((now - before) / Math.abs(before)) * 100;
}
const pct = (v: number) => `${Math.abs(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const r = props.range;
        const prev = previousRange(r.from, r.to);
        const [o, s, c, p, b] = await Promise.all([
            api.get('/reports/overview', { params: r }),
            api.get('/reports/sales', { params: { ...r, by: 'day' } }),
            api.get('/reports/sales', { params: { ...r, by: 'channel' } }),
            prev ? api.get('/reports/overview', { params: prev }).catch(() => null) : Promise.resolve(null),
            api.get('/reports/product-sales', { params: r }).catch(() => null),
        ]);
        overview.value = o.data.data;
        days.value = s.data.data.rows;
        awaiting.value = s.data.data.awaiting ?? null;
        channels.value = c.data.data.rows ?? [];
        channelTotals.value = c.data.data.totals ?? null;
        previous.value = p?.data.data ?? null;
        products.value = ((b?.data.data.rows ?? []) as any[]).filter((x) => x.net > 0).sort((x, y) => y.net - x.net).slice(0, 8);
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [props.range.from, props.range.to], load);

const rec = computed(() => overview.value?.recognized);
const canProfit = computed(() => rec.value?.gross_profit != null);
const deltas = computed(() => ({
    revenue: change(rec.value?.revenue, previous.value?.recognized?.revenue),
    profit: change(rec.value?.gross_profit, previous.value?.recognized?.gross_profit),
}));
/** Marketplace fees of the period (confirmed + estimated), when the role may see profit. */
// Only for shops selling on a marketplace (some fee, or orders whose fee is not known yet); else the shipping KPI.
const fees = computed(() => {
    const t = channelTotals.value;
    if (t?.fees == null || (!Number(t.fees) && !Number(t.fees_unknown_orders ?? 0))) return null;
    return Number(t.fees);
});
const lossLink = computed(() => props.links.find((l) => l.to && 'report' in l.to && l.to.report === 'loss-orders'));
const cogsShare = computed(() => (rec.value?.cogs != null && rec.value.revenue > 0 ? (rec.value.cogs / rec.value.revenue) * 100 : null));

// Revenue per day: cost (slate) + gross profit (green), a loss below 0, as before.
const dayLabel = (d: string) => d.slice(8, 10) + '/' + d.slice(5, 7);
const chart = computed(() => ({
    labels: days.value.map((d) => dayLabel(d.label)),
    series: !canProfit.value
        ? [{ key: 'goods', label: 'Tiền hàng', color: '#2563eb', values: days.value.map((d) => d.goods) }]
        : [
              { key: 'cogs', label: 'Giá vốn', color: '#cbd5e1', values: days.value.map((d) => (d.gross_profit >= 0 ? d.cogs : Math.max(0, d.goods))) },
              { key: 'profit', label: 'Lãi gộp', color: '#2563eb', values: days.value.map((d) => Math.max(0, d.gross_profit)) },
              ...(days.value.some((d) => d.gross_profit < 0) ? [{ key: 'loss', label: 'Lỗ', color: '#f87171', values: days.value.map((d) => Math.min(0, d.gross_profit)) }] : []),
          ],
    tips: days.value.map((d) =>
        [`${dayLabel(d.label)} · ${formatNumber(d.orders)} đơn`, `Tiền hàng: ${formatMoney(d.goods)}`, ...(canProfit.value ? [`Giá vốn: ${formatMoney(d.cogs)}`, `Lãi gộp: ${formatMoney(d.gross_profit)}`] : []), 'Bấm để xem đơn của ngày này'].join('\n'),
    ),
}));
const best = computed(() => {
    let at = -1;
    days.value.forEach((d, i) => {
        if (d.goods > 0 && (at < 0 || d.goods > days.value[at].goods)) at = i;
    });
    return at < 0 ? null : { label: dayLabel(days.value[at].label), goods: days.value[at].goods, margin: days.value[at].margin as number | null };
});
/** The longest run of 5+ days with no recognised order, said in words under the chart. */
const quiet = computed(() => {
    let best: [number, number] | null = null;
    let start = -1;
    days.value.forEach((d, i) => {
        if (d.goods === 0 && d.orders === 0) {
            if (start < 0) start = i;
            if (i - start + 1 >= 5 && (!best || i - start > best[1] - best[0])) best = [start, i];
        } else start = -1;
    });
    return best ? `${dayLabel(days.value[best[0]].label)} – ${dayLabel(days.value[best[1]].label)}` : null;
});
function openDay(i: number) {
    const day = days.value[i]?.label;
    if (day) router.push({ name: 'report-detail', params: { report: 'orders' }, query: { from: day, to: day, basis: 'completed' } });
}
const chartTotal = computed(() => days.value.reduce((s, d) => s + Number(d.goods), 0));
/** Decoration of the blue card: the revenue per day as a soft area. */
const spark = computed(() => {
    const v = days.value.map((d) => Number(d.goods));
    const max = Math.max(...v, 0);
    if (v.length < 2 || max <= 0) return null;
    const pts = v.map((x, i) => `${(i / (v.length - 1)) * 100},${40 - (x / max) * 36}`);
    return `M0,40 L${pts.join(' L')} L100,40 Z`;
});

// Channels.
const kindLabels: Record<string, string> = { shopee: 'Shopee', tiktok: 'TikTok Shop', lazada: 'Lazada', tiki: 'Tiki', woocommerce: 'Website', pos: 'Bán tại quầy', manual: 'Kênh riêng' };
const channelColors: Record<string, string> = { shopee: '#ee4d2d', tiktok: '#111827', lazada: '#1a2a9c', tiki: '#1a94ff', woocommerce: '#7c3aed', pos: '#10b981' };
const channelTotal = computed(() => channels.value.reduce((s: number, c: any) => s + Number(c.goods), 0));
const channelPcts = computed(() => wholePercents(channels.value.map((c: any) => Number(c.goods))));

// Delivery status of orders placed in the period.
const deliveryColors: Record<string, string> = { delivered: '#10b981', shipping: '#3b82f6', waiting: '#f59e0b', return_requested: '#a78bfa', failed: '#f87171', unknown: '#cbd5e1' };
const deliveryTabs: Record<string, Record<string, string>> = { delivered: { tab: 'done' }, shipping: { tab: 'shipping' }, return_requested: { status: 'RETURN_REQUESTED' }, unknown: { tab: 'unknown' } };
const delivery = computed(() => {
    const rows = (overview.value?.delivery ?? []) as any[];
    const whole = wholePercents(rows.map((d) => d.orders));
    return rows.map((d, i) => ({
        key: d.key as string,
        label: d.label as string,
        color: deliveryColors[d.key] ?? '#94a3b8',
        value: d.orders as number,
        pct: whole[i]!,
        to: deliveryTabs[d.key] && tenant.can('orders.view') ? { name: 'orders', query: { ...deliveryTabs[d.key], from: props.range.from, to: props.range.to } } : undefined,
    }));
});
const deliveryTotal = computed(() => delivery.value.reduce((s, d) => s + d.value, 0));
const ring = computed(() => {
    let offset = 0;
    return delivery.value
        .filter((d) => d.value > 0)
        .map((d) => {
            const share = deliveryTotal.value ? (d.value / deliveryTotal.value) * 100 : 0;
            const seg = { key: d.key, color: d.color, dash: `${Math.max(0, share - 0.6)} ${100 - Math.max(0, share - 0.6)}`, offset: -offset };
            offset += share;
            return seg;
        });
});

const byTitle = (t: string) => props.links.find((l) => l.title === t);
const orderLinks = computed(() => props.links.filter((l) => !['Thanh toán', 'Trả hàng'].includes(l.title)));

// Best sellers.
const productTones = ['text-blue-600 bg-blue-50', 'text-red-500 bg-red-50', 'text-amber-500 bg-amber-50', 'text-violet-600 bg-violet-50', 'text-emerald-600 bg-emerald-50'];
const unlinked = (p: any) => !p.variant?.id;
const productName = (p: any) => String(p.variant?.name ?? '').replace(/ \(chưa ghép SKU\)$/, '');

/** "Xuất báo cáo": the headline figures, revenue per day, per channel and the best sellers in one spreadsheet. */
function exportCsv() {
    if (!overview.value) return;
    const r = rec.value;
    const rows: (string | number | null)[][] = [
        ['Báo cáo bán hàng', `${props.range.from} – ${props.range.to}`, null, null],
        [],
        ['Doanh thu thuần', r.revenue, null, null],
        ...(canProfit.value ? [['Giá vốn hàng bán', r.cogs, null, null], ['Lãi gộp', r.gross_profit, null, null]] : []),
        ['Phí giao hàng thu khách', r.shipping ?? 0, null, null],
        ['Số đơn', overview.value.sales.orders, null, null],
        [],
        ['Ngày', 'Đơn', 'Tiền hàng', canProfit.value ? 'Lãi gộp' : null],
        ...days.value.map((d) => [d.label, d.orders, d.goods, canProfit.value ? d.gross_profit : null]),
        [],
        ['Kênh bán', 'Đơn', 'Tiền hàng', canProfit.value ? 'Lãi gộp' : null],
        ...channels.value.map((c: any) => [c.label, c.orders, c.goods, canProfit.value ? c.gross_profit : null]),
        [],
        ['Sản phẩm bán chạy', 'Đã bán', 'Doanh thu', canProfit.value ? 'Lãi gộp' : null],
        ...products.value.map((p) => [`${productName(p)} (${p.variant?.sku ?? ''})`, p.units, p.net, canProfit.value ? p.gross_profit : null]),
    ];
    downloadCsv(`bao-cao-ban-hang_${props.range.from}_${props.range.to}.csv`, toCsv(['Mục', 'Giá trị', '', ''], rows));
}
defineExpose({ exportCsv, ready: computed(() => !!overview.value) });

const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
</script>

<template>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!overview" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /><Skeleton class="h-72 sm:col-span-2 xl:col-span-4" /></div>
    <div v-else :class="loading && 'opacity-60'">
        <!-- Headline figures -->
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="sales-dashboard-kpis">
            <ReportKpi
                primary
                label="Doanh thu thuần"
                tip-title="Doanh thu thuần = tiền hàng − chiết khấu − hàng trả lại"
                tip="Tính trên đơn đã hoàn thành trong kỳ (sàn: hoàn thành; kênh riêng: đã giao; POS: lúc bán). Cùng con số với trang Tài chính và Báo cáo lãi lỗ. Không gồm phí giao hàng thu của khách."
                :icon="CircleCheck"
                :value="rec.revenue"
            >
                <template #chips>
                    <ReportChip v-if="deltas.revenue !== null" tone="onblue" data-testid="sales-delta"><component :is="deltas.revenue >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(deltas.revenue) }}</ReportChip>
                    <span v-if="deltas.revenue !== null" class="text-blue-100">so với kỳ trước</span>
                </template>
                {{ formatNumber(overview.sales.orders) }} đơn<template v-if="rec.returns"> · đã trừ trả hàng {{ formatMoney(rec.returns) }}</template>
                <template #decor>
                    <svg v-if="spark" viewBox="0 0 100 40" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-12 w-full opacity-[0.12]" aria-hidden="true"><path :d="spark" fill="white" /></svg>
                </template>
            </ReportKpi>
            <ReportKpi
                v-if="rec.cogs != null"
                label="Giá vốn hàng bán"
                tip-title="Giá vốn = giá vốn lúc xuất kho − giá vốn hàng trả lại nhập kho"
                tip="Lấy từ sổ kho lúc bàn giao. Dòng hàng chưa có giá vốn không được tính là 0 mà đếm riêng."
                :icon="Box"
                plain
                :value="rec.cogs"
            >
                <template v-if="rec.missing_cost_lines" #chips><ReportChip tone="amber">{{ formatNumber(rec.missing_cost_lines) }} dòng chưa có giá vốn</ReportChip></template>
                <template v-if="cogsShare !== null">Chiếm {{ pct(cogsShare) }} doanh thu thuần</template>
                <template v-else>Theo giá vốn lúc xuất kho</template>
            </ReportKpi>
            <ReportKpi
                v-if="rec.gross_profit != null"
                label="Lãi gộp"
                tip-title="Lãi gộp = Doanh thu thuần − Giá vốn hàng bán"
                tip="Chưa trừ phí sàn, phí thanh toán (xem Báo cáo lãi lỗ). Truy xuất ở Bán hàng theo sản phẩm."
                :icon="TrendingUp"
                :tone="rec.gross_profit < 0 ? 'red' : 'green'"
                :value="rec.gross_profit"
            >
                <template #chips>
                    <ReportChip v-if="deltas.profit !== null" :tone="deltas.profit >= 0 ? 'up' : 'down'"><component :is="deltas.profit >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(deltas.profit) }}</ReportChip>
                    <ReportChip v-if="rec.margin != null">Biên {{ rec.margin.toLocaleString('vi-VN') }}%</ReportChip>
                </template>
                Chưa trừ phí sàn
            </ReportKpi>
            <ReportKpi
                v-if="fees !== null"
                label="Phí sàn"
                tip-title="Phí sàn của đơn ghi nhận trong kỳ"
                tip="Theo đối soát của sàn; đơn chưa đối soát thì ước tính theo biểu phí của shop. Cùng số với Tài chính."
                :icon="Receipt"
                tone="amber"
                plain
                :value="fees"
                data-testid="sales-fees"
            >
                <template #chips>
                    <ReportChip v-if="rec.revenue > 0">{{ pct((fees / rec.revenue) * 100) }} doanh thu</ReportChip>
                    <ReportChip v-if="channelTotals?.fees_unknown_orders" tone="amber">{{ formatNumber(channelTotals.fees_unknown_orders) }} đơn chưa có biểu phí</ReportChip>
                </template>
                <RouterLink v-if="lossLink" :to="target(lossLink)" class="font-medium text-red-600 hover:underline">Xem đơn lỗ sau phí sàn</RouterLink>
                <template v-else>Đã trừ trong Báo cáo lãi lỗ</template>
            </ReportKpi>
            <ReportKpi v-else label="Phí ship thu khách" tip="Phí ship khách trả trên đơn. Dòng riêng, không cộng vào doanh thu thuần." :icon="Truck" tone="blue" plain :value="rec.shipping ?? 0">
                <template #chips><ReportChip>Dòng riêng</ReportChip></template>
                Ngoài doanh thu thuần
            </ReportKpi>
        </div>

        <div v-if="awaiting?.orders" class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-[12px] border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900" data-testid="sales-awaiting">
            <TriangleAlert class="size-4 shrink-0 text-amber-600" />
            <b>{{ formatNumber(awaiting.orders) }} đơn sàn</b> đã giao nhưng sàn chưa báo hoàn thành
            <span class="rounded-full border border-amber-200 bg-white px-2 py-0.5 text-xs font-medium tabular-nums">{{ formatMoney(awaiting.goods) }} tiền hàng</span>
            <span class="text-amber-800">chưa tính vào doanh thu, sẽ cộng khi sàn hoàn thành đơn.</span>
            <RouterLink :to="{ name: 'report-detail', params: { report: 'orders' }, query: { ...range, basis: 'awaiting' } }" class="ml-auto inline-flex items-center gap-1 font-semibold text-amber-700 hover:underline">
                Xem {{ formatNumber(awaiting.orders) }} đơn <ArrowRight class="size-4" />
            </RouterLink>
        </div>

        <!-- Revenue per day and per channel -->
        <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_25rem]">
            <section :class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 class="text-[17px] font-semibold text-slate-900">Tiền hàng theo ngày</h3>
                        <p class="mt-0.5 text-[14px] text-slate-500">Ghi nhận khi hoàn thành · sau chiết khấu, chưa trừ trả hàng</p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold tabular-nums text-slate-900" data-testid="sales-revenue">{{ formatNumber(chartTotal) }}<span class="ml-1 text-sm font-semibold text-slate-400">đ</span></p>
                    </div>
                </header>
                <div class="px-5 pb-4 pt-3">
                    <StackedBarChart :labels="chart.labels" :series="chart.series" :tips="chart.tips" :height="250" clickable @select="openDay" />
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="best">Cao nhất <b class="text-slate-700">{{ best.label }}</b>: {{ formatMoney(best.goods) }}<template v-if="canProfit && best.margin != null"> · lãi gộp {{ best.margin.toLocaleString('vi-VN') }}%</template></span>
                        <span v-if="quiet">{{ quiet }}: không có đơn hoàn thành</span>
                        <span>Bấm một cột để xem đơn của ngày đó.</span>
                    </p>
                </div>
            </section>
            <section :class="card" data-testid="sales-by-channel">
                <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="text-[17px] font-semibold text-slate-900">Theo kênh bán</h3>
                        <p class="mt-0.5 text-[14px] text-slate-500">Tiền hàng trong kỳ theo shop và kênh</p>
                    </div>
                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ channels.length }} kênh</span>
                </header>
                <ul v-if="channels.length" class="divide-y divide-slate-100 px-5">
                    <li v-for="(c, i) in channels.slice(0, 7)" :key="`${c.kind}-${c.label}`" class="py-3">
                        <div class="flex items-center gap-3">
                            <MarketplaceLogo :marketplace="c.kind ?? 'manual'" :name="c.label" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800">{{ c.label }}</p>
                                <p class="text-xs text-muted-foreground">{{ kindLabels[c.kind] ?? 'Kênh riêng' }}</p>
                            </div>
                            <span class="text-sm font-semibold tabular-nums text-slate-900">{{ formatMoney(c.goods) }}</span>
                            <span class="w-9 text-right text-xs tabular-nums text-muted-foreground">{{ channelPcts[i] }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full" :style="{ width: `${channelTotal ? (Number(c.goods) / channelTotal) * 100 : 0}%`, background: channelColors[c.kind] ?? '#3b82f6' }" />
                        </div>
                    </li>
                </ul>
                <p v-else class="p-5 text-sm text-muted-foreground">Chưa có đơn ghi nhận doanh thu trong kỳ.</p>
            </section>
        </div>

        <!-- Delivery status, orders, payments, returns -->
        <div class="mt-5 grid gap-5 lg:grid-cols-2 2xl:grid-cols-[1.3fr_1.15fr_1fr]">
            <section :class="card">
                <header class="border-b border-slate-100 px-5 py-4">
                    <h3 class="text-[17px] font-semibold text-slate-900">Tình trạng giao hàng</h3>
                    <p class="mt-0.5 text-[14px] text-slate-500">Đơn tạo trong kỳ theo tình trạng hiện tại</p>
                </header>
                <div class="flex flex-col items-center gap-5 p-5 sm:flex-row" data-testid="delivery-status">
                    <div class="relative size-32 shrink-0">
                        <svg viewBox="0 0 42 42" class="size-full -rotate-90" role="img" aria-label="Tình trạng giao hàng">
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#f1f5f9" stroke-width="5" />
                            <circle v-for="s in ring" :key="s.key" cx="21" cy="21" r="15.9155" fill="none" :stroke="s.color" stroke-width="5" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" />
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div><p class="text-2xl font-bold tabular-nums text-slate-900">{{ formatNumber(deliveryTotal) }}</p><p class="text-xs text-muted-foreground">đơn tạo</p></div>
                        </div>
                    </div>
                    <ul class="w-full min-w-0 flex-1 space-y-1 text-sm">
                        <li v-for="d in delivery" :key="d.key">
                            <component :is="d.to ? 'RouterLink' : 'div'" :to="d.to" :class="cn('flex items-center gap-2 rounded-md px-1.5 py-1', d.to && 'hover:bg-slate-50')">
                                <i class="size-2.5 shrink-0 rounded-[3px]" :style="{ background: d.color }" />
                                <span class="min-w-0 flex-1 text-slate-700">{{ d.label }}</span>
                                <span class="w-10 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(d.value) }}</span>
                                <span class="w-10 text-right text-xs tabular-nums text-muted-foreground">{{ d.pct }}%</span>
                            </component>
                        </li>
                    </ul>
                </div>
                <p class="px-5 pb-4 text-xs text-muted-foreground">Bấm một tình trạng để mở danh sách đơn.</p>
            </section>

            <section :class="card">
                <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                    <span class="grid size-9 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><ShoppingCart class="size-[18px]" /></span>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-[17px] font-semibold text-slate-900">Đơn hàng</h3>
                        <p class="text-[13px] text-muted-foreground">{{ canProfit ? `Lãi gộp ${formatMoney(rec.gross_profit)}` : 'Đơn đã ghi nhận doanh thu' }}</p>
                    </div>
                    <span class="text-2xl font-bold tabular-nums text-slate-900">{{ formatNumber(overview.sales.orders) }}</span>
                </header>
                <ul class="grid grid-cols-2 gap-x-2 p-3 text-[13px]" data-testid="report-group-sales">
                    <li v-for="l in orderLinks" :key="l.title">
                        <RouterLink :to="target(l)" :class="cn('flex min-w-0 items-center gap-2 rounded-md px-2 py-2 hover:bg-slate-50', l.to && 'report' in l.to && l.to.report === 'loss-orders' ? 'text-red-600' : 'text-slate-700 hover:text-blue-600')">
                            <component :is="l.icon" class="size-4 shrink-0 opacity-70" /><span class="truncate" :title="l.title">{{ l.title }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <div class="grid content-start gap-5 lg:col-span-2 lg:grid-cols-2 2xl:col-span-1 2xl:grid-cols-1">
                <section v-if="byTitle('Thanh toán')" :class="card">
                    <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                        <span class="grid size-9 place-items-center rounded-[10px] bg-emerald-50 text-emerald-600"><CreditCard class="size-[18px]" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-[17px] font-semibold text-slate-900">Thanh toán</h3>
                            <p class="text-[13px] text-muted-foreground">Tiền thực thu trong kỳ</p>
                        </div>
                        <span class="whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ formatNumber(overview.finance?.payments.net ?? 0) }}<span class="ml-0.5 text-sm">đ</span></span>
                    </header>
                    <ul class="grid grid-cols-2 gap-x-2 p-3 text-[13px]">
                        <li v-for="c in byTitle('Thanh toán')!.chips" :key="c.by">
                            <RouterLink :to="target(byTitle('Thanh toán')!, c.by)" class="flex min-w-0 items-center gap-2 rounded-md px-2 py-2 text-slate-700 hover:bg-slate-50 hover:text-blue-600"><FileText class="size-4 shrink-0 opacity-70" /><span class="truncate">{{ c.label }}</span></RouterLink>
                        </li>
                    </ul>
                </section>
                <section v-if="byTitle('Trả hàng')" :class="card">
                    <header class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                        <span class="grid size-9 place-items-center rounded-[10px] bg-violet-50 text-violet-600"><Undo2 class="size-[18px]" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-[17px] font-semibold text-slate-900">Trả hàng</h3>
                            <p class="text-[13px] text-muted-foreground">Đã hoàn {{ formatMoney(overview.returns.refund_amount) }}</p>
                        </div>
                        <span class="text-2xl font-bold tabular-nums text-slate-900">{{ formatNumber(overview.returns.returns) }}</span>
                    </header>
                    <ul class="grid grid-cols-2 gap-x-2 p-3 text-[13px]">
                        <li v-for="c in byTitle('Trả hàng')!.chips ?? []" :key="c.by">
                            <RouterLink :to="target(byTitle('Trả hàng')!, c.by)" class="flex min-w-0 items-center gap-2 rounded-md px-2 py-2 text-slate-700 hover:bg-slate-50 hover:text-blue-600"><FileText class="size-4 shrink-0 opacity-70" /><span class="truncate">{{ c.label }}</span></RouterLink>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- Best sellers -->
        <section v-if="products.length" :class="cn(card, 'mt-5 overflow-hidden')" data-testid="sales-top-products">
            <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="text-[17px] font-semibold text-slate-900">Bán chạy theo sản phẩm</h3>
                    <p class="mt-0.5 text-[14px] text-slate-500">Xếp theo doanh thu trong kỳ</p>
                </div>
                <RouterLink :to="{ name: 'report-detail', params: { report: 'product-sales' }, query: { ...range } }" class="inline-flex items-center gap-1 rounded-[10px] border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Xem tất cả <ArrowRight class="size-4" /></RouterLink>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="w-12 px-5 py-3 font-semibold">#</th>
                            <th class="px-3 py-3 font-semibold">Sản phẩm</th>
                            <th class="px-4 py-3 text-right font-semibold">Đã bán</th>
                            <th class="px-4 py-3 text-right font-semibold">Doanh thu</th>
                            <th v-if="canProfit" class="px-4 py-3 text-right font-semibold">Lãi gộp</th>
                            <th v-if="canProfit" class="w-48 px-5 py-3 text-right font-semibold">Biên lãi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(p, i) in products" :key="p.variant.sku ?? i">
                            <td class="px-5 py-3.5">
                                <span :class="cn('grid size-6 place-items-center rounded-md text-xs font-semibold', i < 3 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500')">{{ i + 1 }}</span>
                            </td>
                            <td class="px-3 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', productTones[i % productTones.length])"><Hexagon class="size-[18px]" /></span>
                                    <div class="min-w-0">
                                        <p class="max-w-xl truncate font-medium text-slate-800">{{ productName(p) }}</p>
                                        <p class="flex items-center gap-2 font-mono text-xs text-muted-foreground">
                                            {{ p.variant.sku }}
                                            <span v-if="unlinked(p)" class="rounded bg-amber-50 px-1.5 py-px font-sans text-[11px] font-medium text-amber-700">Chưa ghép SKU</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ formatNumber(p.units) }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(p.net) }}</td>
                            <td v-if="canProfit" :class="cn('whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums', p.gross_profit < 0 ? 'text-red-600' : 'text-emerald-600')">{{ formatNumber(p.gross_profit) }}</td>
                            <td v-if="canProfit" class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                        <div :class="cn('h-full rounded-full', (p.margin ?? 0) < 0 ? 'bg-red-500' : (p.margin ?? 0) < 20 ? 'bg-amber-500' : 'bg-emerald-500')" :style="{ width: `${Math.max(0, Math.min(100, p.margin ?? 0))}%` }" />
                                    </div>
                                    <span :class="cn('w-12 text-right tabular-nums', p.margin != null && p.margin < 20 ? 'text-amber-600' : 'text-slate-700')">{{ p.margin == null ? '—' : `${p.margin.toLocaleString('vi-VN')}%` }}</span>
                                    <span v-if="p.missing_cost_lines" class="grid size-4 place-items-center rounded bg-amber-50 text-amber-600" :title="`${p.missing_cost_lines} dòng chưa có giá vốn: biên lãi đang cao hơn thực tế`"><CircleHelp class="size-3" /></span>
                                    <span v-else class="size-4" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
