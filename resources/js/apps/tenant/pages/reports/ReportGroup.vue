<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router';
import { ArrowRight, Info } from 'lucide-vue-next';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import Card from '@/shared/ui/Card.vue';
import InfoTip from '@/shared/ui/InfoTip.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import DonutChart from '@/shared/ui/charts/DonutChart.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { reportGroups, type ReportGroupKey, type ReportLink } from './groups';
import { useReportQuery } from './useReportQuery';

/**
 * A report menu like Sapo: "Báo cáo bán hàng" is a dashboard (revenue and profit, delivery status, orders, payments,
 * returns) with links to its reports; the other menus list their reports as cards.
 */
const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const { range } = useReportQuery();
const key = computed(() => route.params.group as ReportGroupKey);
const group = computed(() => reportGroups[key.value]);
const links = computed(() => group.value.links.filter((l) => tenant.can(l.permission)));

function target(l: ReportLink, by?: string): RouteLocationRaw {
    const query = l.ranged ? { ...range.value } : {};
    if ('report' in l.to) return { name: 'report-detail', params: { report: l.to.report }, query: { ...query, ...(by ?? l.to.by ? { by: by ?? l.to.by } : {}) } };
    return { name: l.to.name, query: { ...query, ...(l.to.query ?? {}) } };
}

// Sales dashboard figures.
const overview = ref<any>(null);
const days = ref<any[]>([]);
const awaiting = ref<{ orders: number; goods: number } | null>(null);
const channels = ref<any[]>([]);
const previous = ref<any>(null);
const products = ref<any[]>([]);

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
/** "+12,4% so với kỳ trước" (null when there is nothing to compare with). */
function delta(now: number | null | undefined, before: number | null | undefined): { text: string; up: boolean } | null {
    if (now == null || before == null || !before) return null;
    const pct = ((now - before) / Math.abs(before)) * 100;
    return { text: `${pct >= 0 ? '+' : ''}${pct.toLocaleString('vi-VN', { maximumFractionDigits: 1 })}% so với kỳ trước`, up: pct >= 0 };
}
const error = ref<string | null>(null);
const loading = ref(false);
async function load() {
    if (key.value !== 'sales') return;
    loading.value = true;
    error.value = null;
    try {
        const prev = previousRange(range.value.from, range.value.to);
        const [o, s, c, p, b] = await Promise.all([
            api.get('/reports/overview', { params: range.value }),
            api.get('/reports/sales', { params: { ...range.value, by: 'day' } }),
            api.get('/reports/sales', { params: { ...range.value, by: 'channel' } }),
            prev ? api.get('/reports/overview', { params: prev }).catch(() => null) : Promise.resolve(null),
            api.get('/reports/product-sales', { params: range.value }).catch(() => null),
        ]);
        overview.value = o.data.data;
        days.value = s.data.data.rows;
        awaiting.value = s.data.data.awaiting ?? null;
        channels.value = c.data.data.rows ?? [];
        previous.value = p?.data.data ?? null;
        products.value = ((b?.data.data.rows ?? []) as any[]).filter((r) => r.net > 0).sort((x, y) => y.net - x.net).slice(0, 8);
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [key.value, range.value.from, range.value.to], load);

/**
 * Each bar is the day's goods revenue (giá vốn + lãi gộp). On a day sold at a loss the cost part covered by revenue
 * is drawn up to the revenue and the loss goes below 0, so the bars still add up to the figure in the header.
 */
const dayLabel = (d: string) => d.slice(8, 10) + '/' + d.slice(5, 7);
// Without "xem giá vốn & lợi nhuận" the API leaves cost and profit out: one series, the day's goods revenue.
const canProfit = computed(() => overview.value?.recognized?.gross_profit != null);
const chart = computed(() => ({
    labels: days.value.map((d) => dayLabel(d.label)),
    series: !canProfit.value ? [{ key: 'goods', label: 'Tiền hàng', color: '#60a5fa', values: days.value.map((d) => d.goods) }] : [
        { key: 'cogs', label: 'Giá vốn', color: '#60a5fa', values: days.value.map((d) => (d.gross_profit >= 0 ? d.cogs : Math.max(0, d.goods))) },
        { key: 'profit', label: 'Lãi gộp', color: '#34d399', values: days.value.map((d) => Math.max(0, d.gross_profit)) },
        ...(days.value.some((d) => d.gross_profit < 0) ? [{ key: 'loss', label: 'Lỗ (giá vốn vượt doanh thu)', color: '#f87171', values: days.value.map((d) => Math.min(0, d.gross_profit)) }] : []),
    ],
    tips: days.value.map((d) =>
        [
            `${dayLabel(d.label)} · ${formatNumber(d.orders)} đơn`,
            `Tiền hàng: ${formatMoney(d.goods)}`,
            ...(canProfit.value ? [`Giá vốn: ${formatMoney(d.cogs)}`, `Lãi gộp: ${formatMoney(d.gross_profit)}`] : []),
            'Bấm để xem đơn của ngày này',
        ].join('\n'),
    ),
}));
function openDay(i: number) {
    const day = days.value[i]?.label;
    if (day) router.push({ name: 'report-detail', params: { report: 'orders' }, query: { from: day, to: day, basis: 'completed' } });
}

const deliveryColors: Record<string, string> = { delivered: '#16a34a', shipping: '#22c55e', waiting: '#86efac', return_requested: '#fbbf24', failed: '#cbd5e1', unknown: '#f1f5f9' };
const deliveryTabs: Record<string, Record<string, string>> = { delivered: { tab: 'done' }, shipping: { tab: 'shipping' }, return_requested: { status: 'RETURN_REQUESTED' }, unknown: { tab: 'unknown' } };
const delivery = computed(() =>
    (overview.value?.delivery ?? []).map((d: any) => ({
        key: d.key,
        label: d.label,
        color: deliveryColors[d.key] ?? '#94a3b8',
        value: d.orders,
        to: deliveryTabs[d.key] && tenant.can('orders.view') ? { name: 'orders', query: { ...deliveryTabs[d.key], from: range.value.from, to: range.value.to } } : undefined,
    })),
);
// When revenue counts (ngày đặt hay ngày hoàn thành) comes from the API, so the labels always match the figures.
const basisShort = computed(() => (overview.value?.sales.basis === 'completed' ? 'ghi nhận khi hoàn thành' : 'theo ngày đặt hàng'));
const rec = computed(() => overview.value?.recognized);
const basisNote = computed(() =>
    overview.value?.sales.basis === 'completed'
        ? 'Tính trên đơn đã hoàn thành trong kỳ (sàn: hoàn thành; kênh riêng: đã giao; POS: lúc bán).'
        : 'Tính trên đơn đặt trong kỳ, trừ đơn hủy và đơn hoàn.',
);
const deltas = computed(() => ({
    revenue: delta(rec.value?.revenue, previous.value?.recognized?.revenue),
    profit: delta(rec.value?.gross_profit, previous.value?.recognized?.gross_profit),
    orders: delta(overview.value?.sales.orders, previous.value?.sales.orders),
}));
const channelColors: Record<string, string> = { shopee: '#ee4d2d', tiktok: '#111827', lazada: '#1a2a9c', tiki: '#1a94ff', woocommerce: '#7f54b3', pos: '#10b981' };
const channelTotal = computed(() => channels.value.reduce((s: number, c: any) => s + Number(c.goods), 0));
const deliveryTotal = computed(() => delivery.value.reduce((s: number, d: any) => s + d.value, 0));
const byTitle = (t: string) => links.value.find((l) => l.title === t);
const orderLinks = computed(() => links.value.filter((l) => !['Thanh toán', 'Trả hàng'].includes(l.title)));
</script>

<template>
    <PageHeader :title="group.title">
        <RangePicker v-if="key === 'sales' || links.some((l) => l.ranged)" :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
    </PageHeader>

    <template v-if="key === 'sales'">
        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!overview" class="grid gap-5 lg:grid-cols-[1fr_22rem]"><Skeleton class="h-72" /><Skeleton class="h-72" /></div>
        <template v-else>
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :class="loading && 'opacity-60'" data-testid="sales-dashboard-kpis">
                <KpiCard label="Doanh thu thuần" tone="blue" :value="formatMoney(rec.revenue)">
                    <template #label
                        >Doanh thu thuần
                        <InfoTip title="Doanh thu thuần = tiền hàng − chiết khấu − hàng trả lại">{{ basisNote }} Cùng con số với trang Tài chính và Báo cáo lãi lỗ. Không gồm phí giao hàng thu của khách.</InfoTip></template
                    >
                    {{ formatNumber(overview.sales.orders) }} đơn<template v-if="rec.returns"> · đã trừ trả hàng {{ formatMoney(rec.returns) }}</template>
                    <span v-if="deltas.revenue" :class="cn('block font-medium', deltas.revenue.up ? 'text-emerald-600' : 'text-destructive')" data-testid="sales-delta">{{ deltas.revenue.text }}</span>
                </KpiCard>
                <KpiCard v-if="rec.cogs != null" label="Giá vốn hàng bán" :value="formatMoney(rec.cogs)">
                    <template #label
                        >Giá vốn hàng bán
                        <InfoTip title="Giá vốn = giá vốn lúc xuất kho − giá vốn hàng trả lại nhập kho">Lấy từ sổ kho lúc bàn giao. Dòng hàng chưa có giá vốn không được tính là 0 mà đếm riêng.</InfoTip></template
                    >
                    <span v-if="rec.missing_cost_lines" class="text-amber-600">{{ rec.missing_cost_lines }} dòng chưa có giá vốn</span>
                    <template v-else>theo giá vốn lúc xuất kho</template>
                </KpiCard>
                <KpiCard v-if="rec.gross_profit != null" label="Lãi gộp" :tone="rec.gross_profit < 0 ? 'red' : 'green'" :value="formatMoney(rec.gross_profit)">
                    <template #label
                        >Lãi gộp <InfoTip title="Lãi gộp = Doanh thu thuần − Giá vốn hàng bán">{{ basisNote }} Chưa trừ phí sàn, phí thanh toán (xem Báo cáo lãi lỗ). Truy xuất ở Bán hàng theo sản phẩm.</InfoTip></template
                    >
                    {{ rec.margin == null ? '—' : `biên ${rec.margin.toLocaleString('vi-VN')}%` }} · chưa trừ phí sàn
                    <span v-if="deltas.profit" :class="cn('block font-medium', deltas.profit.up ? 'text-emerald-600' : 'text-destructive')">{{ deltas.profit.text }}</span>
                </KpiCard>
                <KpiCard label="Phí giao hàng thu khách" :value="formatMoney(rec.shipping ?? 0)">
                    <template #label>Phí giao hàng thu khách <InfoTip title="Phí ship khách trả trên đơn">Dòng riêng, không cộng vào doanh thu thuần.</InfoTip></template>
                    dòng riêng, ngoài doanh thu thuần
                </KpiCard>
            </div>
            <p v-if="awaiting?.orders" class="-mt-2 mb-5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="sales-awaiting">
                <Info class="mt-0.5 size-3.5 shrink-0" />
                <span>{{ formatNumber(awaiting.orders) }} đơn sàn đã giao trong kỳ nhưng sàn chưa báo hoàn thành ({{ formatMoney(awaiting.goods) }} tiền hàng): chưa tính vào doanh thu, sẽ được tính khi sàn hoàn thành đơn.</span>
            </p>
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]" :class="loading && 'opacity-60'">
                <Card caps title="Tiền hàng theo ngày" :description="`${basisShort[0]!.toUpperCase()}${basisShort.slice(1)} · tiền hàng sau chiết khấu, chưa trừ trả hàng${canProfit ? ' (giá vốn + lãi gộp)' : ''}`">
                    <template #actions><span class="text-xl font-semibold text-primary" data-testid="sales-revenue">{{ formatMoney(overview.sales.goods) }}</span></template>
                    <div class="p-5"><StackedBarChart :labels="chart.labels" :series="chart.series" :tips="chart.tips" :height="220" clickable @select="openDay" /></div>
                </Card>
                <Card caps title="Theo kênh" description="Tiền hàng trong kỳ theo shop và kênh bán" data-testid="sales-by-channel">
                    <ul v-if="channels.length" class="space-y-4 p-5 text-sm">
                        <li v-for="c in channels.slice(0, 6)" :key="`${c.kind}-${c.label}`">
                            <div class="flex items-center gap-2.5">
                                <MarketplaceLogo :marketplace="c.kind ?? 'manual'" :name="c.label" size="sm" />
                                <span class="min-w-0 flex-1 truncate">{{ c.label }}</span>
                                <span class="font-medium tabular-nums">{{ formatMoney(c.goods) }}</span>
                                <span class="w-10 text-right text-xs text-muted-foreground tabular-nums">{{ channelTotal ? Math.round((Number(c.goods) / channelTotal) * 100) : 0 }}%</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :style="{ width: `${channelTotal ? (Number(c.goods) / channelTotal) * 100 : 0}%`, background: channelColors[c.kind] ?? '#94a3b8' }" /></div>
                        </li>
                    </ul>
                    <p v-else class="p-5 text-sm text-muted-foreground">Chưa có đơn ghi nhận doanh thu trong kỳ.</p>
                </Card>
            </div>
            <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-[minmax(0,1.6fr)_1fr_1fr_1fr]">
                <Card caps title="Thông tin giao hàng" description="Đơn tạo trong kỳ theo tình trạng hiện tại">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatNumber(deliveryTotal) }}</span></template>
                    <div class="p-5" data-testid="delivery-status">
                        <DonutChart :items="delivery" show-value />
                        <p class="mt-3 text-[11px] text-muted-foreground">Tổng % luôn bằng 100% · bấm một tình trạng để mở danh sách đơn.</p>
                    </div>
                </Card>

                <Card caps title="Đơn hàng" :description="rec.gross_profit != null ? `Lãi gộp ${formatMoney(rec.gross_profit)}` : 'Đơn đã ghi nhận doanh thu'">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatNumber(overview.sales.orders) }}</span></template>
                    <ul class="space-y-0.5 p-3 text-sm" data-testid="report-group-sales">
                        <li v-for="l in orderLinks" :key="l.title">
                            <RouterLink :to="target(l)" class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-primary hover:bg-muted/50"><component :is="l.icon" class="size-4 text-muted-foreground" />{{ l.title }}</RouterLink>
                        </li>
                    </ul>
                </Card>
                <Card v-if="byTitle('Thanh toán')" caps title="Thanh toán" description="Tiền thực thu trong kỳ">
                    <template #actions><span class="whitespace-nowrap text-xl font-semibold text-primary">{{ formatMoney(overview.finance?.payments.net ?? 0) }}</span></template>
                    <ul class="space-y-0.5 p-3 text-sm">
                        <li v-for="c in byTitle('Thanh toán')!.chips" :key="c.by">
                            <RouterLink :to="target(byTitle('Thanh toán')!, c.by)" class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-primary hover:bg-muted/50"><component :is="byTitle('Thanh toán')!.icon" class="size-4 text-muted-foreground" />Thanh toán {{ c.label.toLowerCase() }}</RouterLink>
                        </li>
                    </ul>
                </Card>
                <Card caps title="Trả hàng" :description="`Đã hoàn ${formatMoney(overview.returns.refund_amount)}`">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatNumber(overview.returns.returns) }}</span></template>
                    <ul class="space-y-0.5 p-3 text-sm">
                        <li v-for="c in byTitle('Trả hàng')?.chips ?? []" :key="c.by">
                            <RouterLink :to="target(byTitle('Trả hàng')!, c.by)" class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-primary hover:bg-muted/50"><component :is="byTitle('Trả hàng')!.icon" class="size-4 text-muted-foreground" />Trả hàng {{ c.label.toLowerCase() }}</RouterLink>
                        </li>
                    </ul>
                </Card>
            </div>
            <Card v-if="products.length" caps title="Bán chạy theo sản phẩm" class="mt-5" data-testid="sales-top-products">
                <template #actions><RouterLink :to="{ name: 'report-detail', params: { report: 'product-sales' }, query: { ...range } }" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">Xem tất cả <ArrowRight class="size-4" /></RouterLink></template>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">Sản phẩm</th>
                                <th class="px-4 py-2.5 text-right font-medium">Đã bán</th>
                                <th class="px-4 py-2.5 text-right font-medium">Doanh thu</th>
                                <th v-if="canProfit" class="px-4 py-2.5 text-right font-medium">Lãi gộp</th>
                                <th v-if="canProfit" class="px-5 py-2.5 text-right font-medium">Biên lãi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="p in products" :key="p.variant.sku">
                                <td class="px-5 py-3"><p class="max-w-md truncate font-medium">{{ p.variant.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ p.variant.sku }}</p></td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(p.units) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(p.net) }}</td>
                                <td v-if="canProfit" :class="cn('whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums', p.gross_profit < 0 ? 'text-destructive' : 'text-emerald-600')">{{ formatMoney(p.gross_profit) }}</td>
                                <td v-if="canProfit" class="px-5 py-3 text-right tabular-nums">{{ p.margin == null ? '—' : `${p.margin.toLocaleString('vi-VN')}%` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </template>
    </template>

    <template v-else>
        <p class="-mt-3 mb-4 text-sm text-muted-foreground">Danh sách {{ group.title.toLowerCase() }}</p>
        <div class="grid gap-4 md:grid-cols-2" :data-testid="`report-group-${key}`">
            <div v-for="l in links" :key="l.title" class="flex gap-4 rounded-lg border bg-card p-5 shadow-xs">
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><component :is="l.icon" class="size-5" /></span>
                <div class="min-w-0">
                    <RouterLink :to="target(l)" class="font-semibold text-primary hover:underline">{{ l.title }}</RouterLink>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ l.description }}</p>
                    <div v-if="l.chips" class="mt-2 flex flex-wrap gap-1.5">
                        <RouterLink v-for="c in l.chips" :key="c.by" :to="target(l, c.by)" :class="cn('rounded-full border px-2.5 py-0.5 text-xs text-muted-foreground hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700')">{{ c.label }}</RouterLink>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
