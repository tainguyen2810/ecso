<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowUp, ChartColumn, Repeat, Star, Users } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ReportLinkCard from './ReportLinkCard.vue';
import SideCard from './SideCard.vue';
import ChannelMark from './ChannelMark.vue';
import { channelLabel, channelName, groupChannels } from './channels';
import type { ReportLink } from './groups';
import { change, initials, pct, previousRange, short, useDashboard } from './dashboard';
import { downloadCsv, toCsv } from './range';

/**
 * "Báo cáo khách hàng" (mẫu 28/11): customers who bought, new and returning, revenue per customer; the reports of the
 * menu with their figures, new against returning and the top customers. Customers are those with a customer record
 * (walk-in and marketplace buyers cannot be told apart). Debts are as of today.
 */
const props = defineProps<{ range: { from: string; to: string }; links: ReportLink[]; target: (l: ReportLink, by?: string) => RouteLocationRaw }>();
const tenant = useTenantStore();

const { data, error, loading, load } = useDashboard(
    () => props.range,
    async (r) => {
        const prev = previousRange(r.from, r.to);
        const [buyers, sales, channels, receivables, before] = await Promise.all([
            api.get('/reports/customers', { params: r }),
            api.get('/reports/sales', { params: { ...r, by: 'customer' } }),
            api.get('/reports/customers', { params: { ...r, by: 'channel' } }),
            tenant.can('finance.view') ? api.get('/reports/receivables').catch(() => null) : Promise.resolve(null),
            prev ? api.get('/reports/customers', { params: prev }).catch(() => null) : Promise.resolve(null),
        ]);
        return { buyers: buyers.data.data, sales: sales.data.data, channels: channels.data.data, receivables: receivables?.data.data ?? null, before: before?.data.data ?? null };
    },
);

const link = (t: string) => props.links.find((l) => l.title === t);
const s = computed(() => data.value?.buyers.summary ?? { customers: 0, new: 0, returning: 0, revenue: 0, returning_revenue: 0, loyal: 0 });
const prev = computed(() => data.value?.before?.summary ?? null);
// Rows of /reports/sales by customer: the walk-in / marketplace row has no customer code.
const known = computed(() => ((data.value?.sales.rows ?? []) as any[]).filter((r) => r.kind));
const knownOrders = computed(() => known.value.reduce((t, r) => t + Number(r.orders), 0));
const knownRevenue = computed(() => known.value.reduce((t, r) => t + Number(r.revenue), 0));
const knownProfit = computed(() => (known.value.some((r) => r.gross_profit != null) ? known.value.reduce((t, r) => t + Number(r.gross_profit ?? 0), 0) : null));
const perCustomer = computed(() => (s.value.customers ? knownRevenue.value / s.value.customers : 0));
const profitPerCustomer = computed(() => (knownProfit.value !== null && s.value.customers ? knownProfit.value / s.value.customers : null));
const top10Share = computed(() => {
    const top = known.value.slice().sort((a, b) => Number(b.revenue) - Number(a.revenue)).slice(0, 10).reduce((t, r) => t + Number(r.revenue), 0);
    return knownRevenue.value ? (top / knownRevenue.value) * 100 : null;
});
const top = computed(() => known.value.slice().sort((a, b) => Number(b.revenue) - Number(a.revenue)).slice(0, 5));
const newShare = computed(() => (s.value.customers ? (s.value.new / s.value.customers) * 100 : null));
const returnRate = computed(() => (s.value.customers ? (s.value.returning / s.value.customers) * 100 : null));
const returningRevenue = computed(() => (s.value.revenue ? (s.value.returning_revenue / s.value.revenue) * 100 : null));
const newSource = computed(() => {
    const rows = ((data.value?.channels.rows ?? []) as any[]).filter((r) => r.new > 0).sort((a, b) => b.new - a.new);
    return rows[0] ? channelLabel(rows[0].label, rows[0].kind) : null;
});
const oldestDebt = computed(() => Math.max(0, ...((data.value?.receivables?.rows ?? []) as any[]).map((r) => Number(r.age_days ?? 0))));
const oldestName = computed(() => (((data.value?.receivables?.rows ?? []) as any[]).find((r) => Number(r.age_days ?? 0) === oldestDebt.value)?.customer?.name ?? null) as string | null);
/**
 * "Khách theo kênh bán" (mẫu 28/11 b): every channel with orders in the period, grouped Sàn TMĐT / Website / Chat /
 * Tại cửa hàng, each shop or site one row with its real logo (marketplace buyers often have no customer record, so a
 * TikTok shop with orders but no known customer still shows).
 */
const channelRows = computed(() => {
    const rows = ((data.value?.channels.rows ?? []) as any[]).filter((r) => Number(r.orders) > 0 || Number(r.customers) > 0);
    const customers = rows.reduce((t, r) => t + Number(r.customers), 0);
    const orders = rows.reduce((t, r) => t + Number(r.orders), 0);
    const share = (r: any) => (customers ? (Number(r.customers) / customers) * 100 : orders ? (Number(r.orders) / orders) * 100 : 0);
    return rows
        .map((r, i) => ({
            key: `${r.kind}-${r.label}-${i}`,
            kind: (r.kind ?? null) as string | null,
            label: channelLabel(r.label, r.kind),
            channel: channelName(r.kind),
            customers: Number(r.customers),
            new: Number(r.new),
            orders: Number(r.orders),
            share: share(r),
        }))
        .sort((x, y) => y.customers - x.customers || y.orders - x.orders);
});
const byChannel = computed(() =>
    groupChannels(channelRows.value, (r) => r.kind).map((g) => ({
        ...g,
        customers: g.rows.reduce((t, r) => t + r.customers, 0),
        share: g.rows.reduce((t, r) => t + r.share, 0),
    })),
);
const byCustomers = computed(() => channelRows.value.some((r) => r.customers > 0));
const deltaCustomers = computed(() => change(s.value.customers, prev.value?.customers));
const deltaReturning = computed(() => change(s.value.returning, prev.value?.returning));
const avatarTones = ['bg-blue-50 text-blue-600', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600', 'bg-sky-50 text-sky-600'];

function exportCsv() {
    if (!data.value) return;
    const rows: (string | number | null)[][] = [
        ['Báo cáo khách hàng', `${props.range.from} – ${props.range.to}`, null],
        [],
        ['Khách mua hàng', s.value.customers, null],
        ['Khách mới', s.value.new, null],
        ['Khách quay lại', s.value.returning, null],
        ['Khách mua từ 3 lần', s.value.loyal, null],
        ['Doanh thu trung bình / khách', Math.round(perCustomer.value), null],
        ...(data.value.receivables ? [['Khách còn nợ (hôm nay)', data.value.receivables.totals.due, null]] : []),
        [],
        ['Khách hàng', 'Đơn', 'Doanh thu'],
        ...known.value.map((r) => [`${r.label} (${r.kind})`, r.orders, r.revenue]),
    ];
    downloadCsv(`bao-cao-khach-hang_${props.range.from}_${props.range.to}.csv`, toCsv(['Mục', 'Giá trị', ''], rows));
}
defineExpose({ exportCsv });
</script>

<template>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div>
    <div v-else :class="loading && 'opacity-60'" data-testid="report-group-customers">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi primary label="Khách mua hàng" tip="Khách có hồ sơ mua trong kỳ. Đơn khách lẻ và đơn sàn không có hồ sơ khách nên không đếm vào đây." :icon="Users" unit="khách" :value="s.customers">
                <template v-if="deltaCustomers !== null" #chips>
                    <ReportChip tone="onblue"><component :is="deltaCustomers >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(deltaCustomers) }}</ReportChip>
                    <span class="text-blue-100">so với kỳ trước</span>
                </template>
                {{ formatNumber(knownOrders) }} đơn<template v-if="s.customers"> · {{ (knownOrders / s.customers).toLocaleString('vi-VN', { maximumFractionDigits: 2 }) }} đơn/khách</template>
            </ReportKpi>
            <ReportKpi label="Khách mới" tip="Khách có đơn đầu tiên trong kỳ." :icon="Star" tone="violet" plain unit="khách" :value="s.new">
                <template v-if="newShare !== null" #chips><ReportChip>{{ pct(newShare) }} tổng khách</ReportChip></template>
                {{ newSource ? `Chủ yếu từ ${newSource}` : 'Chưa có khách mới trong kỳ' }}
            </ReportKpi>
            <ReportKpi label="Khách quay lại" tip="Khách đã từng mua trước kỳ và mua lại trong kỳ." :icon="Repeat" tone="green" unit="khách" :value="s.returning">
                <template #chips>
                    <ReportChip v-if="deltaReturning !== null" :tone="deltaReturning >= 0 ? 'up' : 'down'"><component :is="deltaReturning >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(deltaReturning) }}</ReportChip>
                    <ReportChip v-if="returnRate !== null">Tỷ lệ {{ pct(returnRate) }}</ReportChip>
                </template>
                <template v-if="returningRevenue !== null">Mang về {{ pct(returningRevenue) }} doanh thu khách có hồ sơ</template>
            </ReportKpi>
            <ReportKpi label="Doanh thu TB / khách" tip="Doanh thu thuần của khách có hồ sơ chia cho số khách mua trong kỳ." :icon="ChartColumn" tone="slate" plain :value="Math.round(perCustomer)">
                <template v-if="profitPerCustomer !== null" #chips><ReportChip>Lãi gộp TB {{ formatNumber(Math.round(profitPerCustomer)) }} đ</ReportChip></template>
                Theo doanh thu thuần
            </ReportKpi>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0">
                <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Danh sách báo cáo</h2>
                <div class="space-y-4">
                    <ReportLinkCard
                        v-if="link('Khách mua hàng')"
                        :link="link('Khách mua hàng')!"
                        :target="target"
                        :stats="[
                            { label: 'Khách mua', value: formatNumber(s.customers), unit: 'khách', hint: `${formatNumber(knownOrders)} đơn${s.customers ? ` · ${(knownOrders / s.customers).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} đơn/khách` : ''}` },
                            { label: 'Khách mới', value: formatNumber(s.new), unit: 'khách', hint: newShare !== null ? `${pct(newShare)} số khách` : null, tone: 'violet' },
                            { label: 'Quay lại', value: formatNumber(s.returning), unit: 'khách', hint: returnRate !== null ? `${pct(returnRate)} số khách` : null, tone: 'green' },
                            { label: 'Mua từ 3 lần', value: formatNumber(s.loyal), unit: 'khách', hint: 'Khách thân thiết' },
                        ]"
                    />
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard
                            v-if="link('Doanh thu, lợi nhuận theo khách')"
                            :link="link('Doanh thu, lợi nhuận theo khách')!"
                            :target="target"
                            tone="green"
                            :stats="[
                                { label: 'Trung bình/khách', value: formatNumber(Math.round(perCustomer)), unit: 'đ', hint: 'Doanh thu thuần' },
                                ...(top10Share !== null ? [{ label: 'Top 10 khách', value: pct(top10Share), hint: 'Phần doanh thu' }] : []),
                                ...(profitPerCustomer !== null ? [{ label: 'Lãi gộp/khách', value: formatNumber(Math.round(profitPerCustomer)), unit: 'đ', hint: 'Sau giá vốn', tone: 'green' as const }] : []),
                            ]"
                        />
                        <ReportLinkCard
                            v-if="link('Công nợ khách hàng') && data.receivables"
                            :link="link('Công nợ khách hàng')!"
                            :target="target"
                            tone="amber"
                            :badge="data.receivables.totals.customers ? `${data.receivables.totals.customers} khách` : null"
                            badge-tone="amber"
                            :stats="[
                                { label: 'Còn nợ', value: short(data.receivables.totals.due), hint: `${formatNumber(data.receivables.totals.customers)} khách`, tone: 'amber' },
                                { label: 'Đơn còn nợ', value: formatNumber(data.receivables.totals.orders), unit: 'đơn', hint: 'Tính đến hôm nay' },
                                ...(oldestDebt ? [{ label: 'Lâu nhất', value: formatNumber(oldestDebt), unit: 'ngày', hint: oldestName, tone: (oldestDebt > 30 ? 'red' : 'amber') as 'red' | 'amber' }] : []),
                            ]"
                        />
                    </div>
                </div>

                <template v-if="byChannel.length">
                    <h2 class="mb-3 mt-6 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Khách theo kênh bán</h2>
                    <section class="overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="customers-by-channel">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-[12px] font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pl-5 text-left font-semibold">Kênh, shop</th>
                                    <th class="hidden py-3 text-left font-semibold md:table-cell">Tỷ trọng</th>
                                    <th class="py-3 text-right font-semibold">Khách</th>
                                    <th class="hidden py-3 text-right font-semibold sm:table-cell">Khách mới</th>
                                    <th class="py-3 text-right font-semibold">Đơn</th>
                                    <th class="py-3 pr-5 text-right font-semibold">%</th>
                                </tr>
                            </thead>
                            <tbody v-for="g in byChannel" :key="g.key" :data-testid="`channel-group-${g.key}`">
                                <tr class="bg-slate-50/80">
                                    <td colspan="6" class="px-5 py-2">
                                        <span class="flex items-center justify-between gap-3 text-[12px] font-semibold uppercase tracking-wider text-slate-500">
                                            <span>{{ g.label }}</span>
                                            <span class="font-medium normal-case tracking-normal">{{ byCustomers ? `${formatNumber(g.customers)} khách` : `${formatNumber(g.rows.reduce((t, r) => t + r.orders, 0))} đơn` }} · {{ pct(g.share) }}</span>
                                        </span>
                                    </td>
                                </tr>
                                <tr v-for="c in g.rows" :key="c.key" class="border-t border-slate-100">
                                    <td class="py-3 pl-5">
                                        <span class="flex min-w-0 items-center gap-3">
                                            <ChannelMark :kind="c.kind" :name="c.channel" />
                                            <span class="min-w-0">
                                                <span class="block truncate text-[14px] font-medium text-slate-900">{{ c.label }}</span>
                                                <span v-if="c.channel !== c.label" class="block truncate text-[12px] text-slate-500">{{ c.channel }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="hidden w-[30%] py-3 pr-6 md:table-cell">
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${c.share ? Math.max(1, c.share) : 0}%` }" /></div>
                                    </td>
                                    <td class="py-3 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(c.customers) }}</td>
                                    <td class="hidden py-3 text-right tabular-nums text-slate-600 sm:table-cell">{{ formatNumber(c.new) }}</td>
                                    <td class="py-3 text-right tabular-nums text-slate-600">{{ formatNumber(c.orders) }}</td>
                                    <td class="py-3 pr-5 text-right text-[13px] tabular-nums text-slate-500">{{ pct(c.share) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </template>
            </div>

            <aside class="space-y-4">
                <SideCard title="Khách mới và quay lại" description="Theo số khách trong kỳ">
                    <div class="px-5 py-4">
                        <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full bg-violet-500" :style="{ width: `${s.customers ? (s.new / s.customers) * 100 : 0}%` }" />
                            <div class="h-full bg-emerald-500" :style="{ width: `${s.customers ? (s.returning / s.customers) * 100 : 0}%` }" />
                        </div>
                        <div class="mt-2.5 flex justify-between text-[13px] text-slate-600">
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-violet-500" />Khách mới · {{ formatNumber(s.new) }}</span>
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-emerald-500" />Quay lại · {{ formatNumber(s.returning) }}</span>
                        </div>
                    </div>
                </SideCard>
                <SideCard title="Top khách hàng" description="Theo doanh thu trong kỳ">
                    <template v-if="link('Doanh thu, lợi nhuận theo khách')" #actions>
                        <RouterLink :to="target(link('Doanh thu, lợi nhuận theo khách')!)" class="rounded-[9px] border border-slate-200 px-3 py-1 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Xem tất cả</RouterLink>
                    </template>
                    <ul v-if="top.length" class="divide-y divide-slate-100 px-5">
                        <li v-for="(c, i) in top" :key="c.kind" class="flex items-center gap-3 py-3">
                            <span :class="['grid size-9 shrink-0 place-items-center rounded-[10px] text-xs font-bold', avatarTones[i % avatarTones.length]]">{{ initials(c.label) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800">{{ c.label }}</p>
                                <p class="text-xs text-slate-500">{{ formatNumber(c.orders) }} đơn · {{ c.kind }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums text-slate-900">{{ formatNumber(c.revenue) }}</p>
                                <p v-if="c.gross_profit != null" class="text-xs tabular-nums text-slate-500">lãi {{ formatNumber(c.gross_profit) }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-5 text-sm text-slate-500">Chưa có khách có hồ sơ mua trong kỳ.</p>
                </SideCard>
            </aside>
        </div>
    </div>
</template>
