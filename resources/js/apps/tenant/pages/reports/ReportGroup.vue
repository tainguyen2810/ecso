<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import Card from '@/shared/ui/Card.vue';
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
const error = ref<string | null>(null);
const loading = ref(false);
async function load() {
    if (key.value !== 'sales') return;
    loading.value = true;
    error.value = null;
    try {
        const [o, s] = await Promise.all([api.get('/reports/overview', { params: range.value }), api.get('/reports/sales', { params: { ...range.value, by: 'day' } })]);
        overview.value = o.data.data;
        days.value = s.data.data.rows;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [key.value, range.value.from, range.value.to], load);

const chart = computed(() => ({
    labels: days.value.map((d) => d.label.slice(8, 10) + '/' + d.label.slice(5, 7)),
    series: [
        { key: 'cogs', label: 'Giá vốn', color: '#60a5fa', values: days.value.map((d) => Math.max(0, d.cogs)) },
        { key: 'profit', label: 'Lãi gộp', color: '#34d399', values: days.value.map((d) => Math.max(0, d.gross_profit)) },
    ],
}));
const deliveryColors: Record<string, string> = { delivered: '#16a34a', shipping: '#22c55e', waiting: '#86efac', failed: '#cbd5e1' };
const delivery = computed(() => (overview.value?.delivery ?? []).map((d: any) => ({ key: d.key, label: d.label, color: deliveryColors[d.key] ?? '#94a3b8', value: d.orders })));
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
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]" :class="loading && 'opacity-60'">
                <Card caps title="Doanh thu cửa hàng" description="Theo ngày bán · cột = doanh thu hàng hóa (giá vốn + lãi gộp)">
                    <template #actions><span class="text-xl font-semibold text-primary" data-testid="sales-revenue">{{ formatMoney(overview.sales.revenue) }}</span></template>
                    <div class="p-5"><StackedBarChart :labels="chart.labels" :series="chart.series" :height="220" /></div>
                </Card>
                <Card caps title="Thông tin giao hàng" description="Đơn tạo trong kỳ theo tình trạng">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatNumber(deliveryTotal) }}</span></template>
                    <div class="p-5" data-testid="delivery-status"><DonutChart :items="delivery" /></div>
                </Card>
            </div>
            <div class="mt-5 grid gap-5 lg:grid-cols-3">
                <Card caps title="Đơn hàng" :description="`Lãi gộp ${formatMoney(overview.sales.gross_profit)}`">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatNumber(overview.sales.orders) }}</span></template>
                    <ul class="space-y-0.5 p-3 text-sm" data-testid="report-group-sales">
                        <li v-for="l in orderLinks" :key="l.title">
                            <RouterLink :to="target(l)" class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-primary hover:bg-muted/50"><component :is="l.icon" class="size-4 text-muted-foreground" />{{ l.title }}</RouterLink>
                        </li>
                    </ul>
                </Card>
                <Card v-if="byTitle('Thanh toán')" caps title="Thanh toán" description="Tiền thực thu trong kỳ">
                    <template #actions><span class="text-xl font-semibold text-primary">{{ formatMoney(overview.finance?.payments.net ?? 0) }}</span></template>
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
