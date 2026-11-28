<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowUp, ChartColumn, Globe, MessageCircle, Phone, Repeat, Star, Store, Users } from 'lucide-vue-next';
import { channelTypes } from '../orders/labels';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ReportLinkCard from './ReportLinkCard.vue';
import SideCard from './SideCard.vue';
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
    return rows[0]?.label ?? null;
});
const oldestDebt = computed(() => Math.max(0, ...((data.value?.receivables?.rows ?? []) as any[]).map((r) => Number(r.age_days ?? 0))));
/** "Khách theo kênh bán": real logos for the marketplaces and Zalo, a plain icon for the shop's own channels. */
const marketLogos: Record<string, string> = { shopee: '/images/marketplaces/shopee.webp', tiktok: '/images/marketplaces/tiktok.webp', lazada: '/images/marketplaces/lazada.webp', tiki: '/images/marketplaces/tiki.webp', zalo: '/images/channels/zalo.webp' };
const kindTones: Record<string, { bar: string; icon: string }> = {
    shopee: { bar: 'bg-orange-500', icon: '' },
    tiktok: { bar: 'bg-slate-900', icon: '' },
    lazada: { bar: 'bg-indigo-500', icon: '' },
    tiki: { bar: 'bg-sky-500', icon: '' },
    zalo: { bar: 'bg-blue-500', icon: '' },
    website: { bar: 'bg-violet-500', icon: 'bg-violet-50 text-violet-600' },
    phone: { bar: 'bg-emerald-500', icon: 'bg-emerald-50 text-emerald-600' },
};
const kindIcons: Record<string, unknown> = { website: Globe, phone: Phone, facebook: MessageCircle };
const byChannel = computed(() => {
    const rows = ((data.value?.channels.rows ?? []) as any[]).filter((r) => Number(r.customers) > 0);
    const total = rows.reduce((t, r) => t + Number(r.customers), 0);
    return rows
        .slice()
        .sort((a, b) => Number(b.customers) - Number(a.customers))
        .slice(0, 6)
        .map((r, i) => {
            const kind = String(r.kind ?? 'other');
            return {
                key: `${kind}-${r.label}-${i}`,
                label: r.label === 'Khác' && channelTypes[kind] ? channelTypes[kind] : r.label,
                name: kind,
                logo: marketLogos[kind] ?? null,
                icon: kindIcons[kind] ?? Store,
                tone: kindTones[kind] ?? { bar: 'bg-blue-600', icon: 'bg-blue-50 text-blue-600' },
                customers: Number(r.customers),
                new: Number(r.new),
                orders: Number(r.orders),
                share: total ? (Number(r.customers) / total) * 100 : 0,
            };
        });
});
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
                            { label: 'Khách mua', value: formatNumber(s.customers) },
                            { label: 'Khách mới', value: formatNumber(s.new), tone: 'violet' },
                            { label: 'Quay lại', value: formatNumber(s.returning), tone: 'green' },
                            { label: 'Mua từ 3 lần', value: formatNumber(s.loyal) },
                        ]"
                    />
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard
                            v-if="link('Doanh thu, lợi nhuận theo khách')"
                            :link="link('Doanh thu, lợi nhuận theo khách')!"
                            :target="target"
                            tone="green"
                            :stats="[{ label: 'TB/khách', value: `${formatNumber(Math.round(perCustomer))} đ` }, ...(top10Share !== null ? [{ label: 'Top 10', value: pct(top10Share) }] : [])]"
                        />
                        <ReportLinkCard
                            v-if="link('Công nợ khách hàng') && data.receivables"
                            :link="link('Công nợ khách hàng')!"
                            :target="target"
                            tone="amber"
                            :badge="data.receivables.totals.customers ? `${data.receivables.totals.customers} khách` : null"
                            badge-tone="amber"
                            :stats="[{ label: 'Còn nợ', value: short(data.receivables.totals.due), tone: 'amber' }, ...(oldestDebt ? [{ label: 'Lâu nhất', value: `${oldestDebt} ngày`, tone: (oldestDebt > 30 ? 'red' : 'amber') as 'red' | 'amber' }] : [])]"
                        />
                    </div>
                </div>

                <template v-if="byChannel.length">
                    <h2 class="mb-3 mt-6 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Khách theo kênh bán</h2>
                    <div class="rounded-2xl border border-slate-200 bg-white px-6 py-2 shadow-sm" data-testid="customers-by-channel">
                        <div v-for="c in byChannel" :key="c.key" class="border-b border-slate-100 py-4 last:border-0">
                            <div class="flex items-center gap-3">
                                <img v-if="c.logo" :src="c.logo" :alt="c.name" class="size-8 shrink-0 rounded-[9px] object-contain" />
                                <span v-else :class="['grid size-8 shrink-0 place-items-center rounded-[9px]', c.tone.icon]"><component :is="c.icon" class="size-4" /></span>
                                <span class="min-w-0 flex-1 truncate text-[15px] font-medium text-slate-800">{{ c.label }}</span>
                                <span class="text-[15px] font-semibold tabular-nums text-slate-900">{{ formatNumber(c.customers) }} khách</span>
                                <span class="w-12 text-right text-[13px] tabular-nums text-slate-500">{{ pct(c.share) }}</span>
                            </div>
                            <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div :class="['h-full rounded-full', c.tone.bar]" :style="{ width: `${Math.max(2, c.share)}%` }" />
                            </div>
                            <p v-if="c.new" class="mt-1.5 text-xs text-slate-500">{{ formatNumber(c.new) }} khách mới · {{ formatNumber(c.orders) }} đơn</p>
                        </div>
                    </div>
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
