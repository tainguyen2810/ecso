<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import {
    ArrowRight,
    Banknote,
    BarChart3,
    CheckCircle2,
    ChevronRight,
    Circle,
    CircleAlert,
    CircleX,
    ClipboardCheck,
    Clock,
    CreditCard,
    HandHelping,
    Package,
    PackageOpen,
    PlugZap,
    Plus,
    Rocket,
    ShoppingCart,
    Store,
    TriangleAlert,
    Truck,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import IconBubble from '@/shared/ui/IconBubble.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import StatTile from '@/shared/ui/StatTile.vue';
import DonutChart from '@/shared/ui/charts/DonutChart.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api } from '@/shared/lib/api';
import { toneClasses, type Tone } from '@/shared/lib/tones';
import { arrangeStartSteps, channelColors, channelLabel, startProgress, type RevenueChannel, type StartStep } from './dashboard';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { marketplaceNames, orderStatus } from './orders/labels';
import type { MarketplaceAccountData, OrderSummary } from '@/shared/types';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';

const auth = useAuthStore();
const tenant = useTenantStore();

interface Summary {
    today_orders: number;
    today_ordered_revenue: string;
    yesterday_ordered_revenue: string;
    today_cancelled: number;
    today_returns: number;
    to_process: number;
    overdue: number;
    shortage: number;
    queues: Record<string, number>;
    days: number;
    revenue_by_day: { date: string; revenue: string; orders: number; channels: Record<string, string> }[];
    revenue_by_channel: RevenueChannel[];
    recent: OrderSummary[];
}

const days = ref(7);
const orders = ref<Summary | null>(null);
const stock = ref<{ on_hand: number; inventory_value: string | null; low_stock: number; out_of_stock: number } | null>(null);
const accounts = ref<MarketplaceAccountData[] | null>(null);
const best = ref<{ variant: { id: string; sku: string; name: string } | null; units: number }[] | null>(null);
const salesChannels = ref<number | null>(null);
const members = ref<number | null>(null);
const lowStock = ref<{ items: { variant_id: string; sku: string; name: string; warehouse: string; available: number; safety_stock: number }[]; total: number } | null>(null);

function loadOrders() {
    api.get('/orders/summary', { params: { days: days.value } }).then(({ data }) => (orders.value = data.data)).catch(() => {});
}
function loadBest() {
    const to = new Date();
    const from = new Date(to.getTime() - (days.value - 1) * 86400000);
    const iso = (d: Date) => d.toISOString().slice(0, 10);
    api.get('/reports/best-sellers', { params: { from: iso(from), to: iso(to) } }).then(({ data }) => (best.value = data.data.slice(0, 5))).catch(() => {});
}
function loadRange() {
    if (tenant.can('orders.view')) loadOrders();
    if (tenant.can('reports.view')) loadBest();
}

onMounted(() => {
    loadRange();
    if (tenant.can('inventory.view')) api.get('/inventory/summary').then(({ data }) => (stock.value = data.data)).catch(() => {});
    if (tenant.can('inventory.view')) api.get('/inventory/low-stock').then(({ data }) => (lowStock.value = { items: data.data, total: data.meta.total })).catch(() => {});
    if (tenant.can('marketplace.view')) api.get('/marketplace-accounts').then(({ data }) => (accounts.value = data.data)).catch(() => {});
    if (tenant.can('orders.view')) api.get('/sales-channels').then(({ data }) => (salesChannels.value = data.data.length)).catch(() => {});
    if (tenant.can('members.view')) api.get('/members').then(({ data }) => (members.value = data.data.length)).catch(() => {});
});
watch(days, loadRange);

const rangeLabel = computed(() => `${days.value} ngày qua`);
const change = computed(() => {
    if (!orders.value) return null;
    const yesterday = Number(orders.value.yesterday_ordered_revenue);
    if (yesterday <= 0) return null;
    return Math.round(((Number(orders.value.today_ordered_revenue) - yesterday) / yesterday) * 100);
});

// Each marketplace and each own sales channel (shop counter, Fanpage, Zalo...) is its own series.
const revenueChannels = computed(() => orders.value?.revenue_by_channel ?? []);
const colors = computed(() => channelColors(revenueChannels.value));
const chart = computed(() => {
    const rows = orders.value?.revenue_by_day ?? [];
    return {
        labels: rows.map((r) => `${r.date.slice(8, 10)}/${r.date.slice(5, 7)}`),
        series: revenueChannels.value.map((c) => ({ key: c.key, label: channelLabel(c, marketplaceNames), color: colors.value[c.key]!, values: rows.map((r) => Number(r.channels[c.key] ?? 0)) })),
        total: rows.reduce((s, r) => s + Number(r.revenue), 0),
    };
});
const share = computed(() => revenueChannels.value.map((c) => ({ key: c.key, label: channelLabel(c, marketplaceNames), color: colors.value[c.key]!, value: Number(c.revenue) })));

// One blue for every queue; red only where something needs checking.
const queues: { tab: string; label: string; icon: Component; tone: Tone }[] = [
    { tab: 'new', label: 'Chờ thanh toán', icon: CreditCard, tone: 'blue' },
    { tab: 'to_confirm', label: 'Chờ xác nhận', icon: ClipboardCheck, tone: 'blue' },
    { tab: 'to_pack', label: 'Chờ đóng gói', icon: PackageOpen, tone: 'blue' },
    { tab: 'to_handover', label: 'Chờ bàn giao', icon: HandHelping, tone: 'blue' },
    { tab: 'shipping', label: 'Đang giao', icon: Truck, tone: 'blue' },
    { tab: 'unknown', label: 'Cần kiểm tra', icon: TriangleAlert, tone: 'red' },
];

const stockBoxes = computed(() => {
    const s = stock.value;
    if (!s) return [];
    return [
        { label: 'SKU dưới tồn an toàn', value: formatNumber(s.low_stock), tone: 'red' as Tone, alert: s.low_stock > 0, to: tenant.can('purchasing.view') ? '/purchasing/reorder' : '/inventory' },
        { label: 'SKU đã hết hàng', value: formatNumber(s.out_of_stock), tone: 'amber' as Tone, alert: false, to: '/inventory' },
        { label: 'Tổng tồn kho', value: formatNumber(s.on_hand), tone: 'slate' as Tone, alert: false, to: '/inventory' },
        // Hidden without "products.cost.view" (the API sends null).
        ...(s.inventory_value != null ? [{ label: 'Giá trị tồn kho (giá vốn)', value: formatMoney(s.inventory_value), tone: 'slate' as Tone, alert: false, to: '/inventory' }] : []),
    ];
});

const accountTone = (a: MarketplaceAccountData) => (a.status !== 'connected' ? 'muted' : a.circuit_open || a.last_error ? 'danger' : 'success');
const accountLabel = (a: MarketplaceAccountData) => (a.status !== 'connected' ? 'Đã ngắt' : a.circuit_open ? 'Tạm dừng do lỗi' : a.last_error ? 'Có lỗi' : 'Đang hoạt động');

// "Bắt đầu": setup steps, ticked from what the shop already has. Hidden per browser with "Ẩn".
const connected = computed(() => (accounts.value ?? []).some((a) => a.status === 'connected'));
const steps = computed<StartStep[]>(() => arrangeStartSteps([
    { key: 'shop', label: 'Tạo cửa hàng', detail: 'Xong.', done: true, to: null, action: '' },
    { key: 'products', label: 'Thêm sản phẩm và nhập kho', detail: 'Nhập tay, hoặc tải file Excel có SKU và tồn đầu kỳ.', done: (stock.value?.on_hand ?? 0) > 0, to: tenant.can('products.view') ? '/products' : null, action: 'Thêm sản phẩm' },
    { key: 'order', label: 'Tạo đơn đầu tiên', detail: 'Đơn Fanpage, Zalo, hoặc bán tại quầy.', done: !!orders.value?.recent.length, to: tenant.can('orders.create') ? '/orders/new' : null, action: 'Tạo đơn' },
    { key: 'channels', label: 'Tạo kênh bán riêng', detail: 'Để xem doanh thu tách theo cửa hàng, Fanpage, Zalo…', done: (salesChannels.value ?? 0) > 1, to: tenant.can('orders.view') ? '/sales-channels' : null, action: 'Thêm kênh' },
    { key: 'members', label: 'Mời nhân viên và phân quyền', detail: 'Kho chỉ thấy đơn và tồn, không thấy tiền.', done: (members.value ?? 0) > 1, to: tenant.can('members.view') ? '/members' : null, action: 'Mời' },
    { key: 'marketplace', label: 'Kết nối sàn TMĐT', detail: 'Shopee, TikTok Shop, WooCommerce.', done: connected.value, optional: true, to: tenant.can('marketplace.view') ? '/marketplaces' : null, action: 'Kết nối' },
], tenant.current?.channels, tenant.current?.team_size));
const progress = computed(() => startProgress(steps.value));
const startKey = `ecso.dashboard.start-hidden.${tenant.current?.id ?? ''}`;
const startHidden = ref(readHidden());
function readHidden() {
    try {
        return localStorage.getItem(startKey) === '1';
    } catch {
        return false;
    }
}
function hideStart() {
    startHidden.value = true;
    try {
        localStorage.setItem(startKey, '1');
    } catch {
        // Private mode: hidden for this visit only.
    }
}
/** A shop without any order yet: the page leads with the setup steps instead of empty charts. */
const isNew = computed(() => !!orders.value && !orders.value.recent.length);
const showStart = computed(() => !startHidden.value && !progress.value.finished);
</script>

<template>
    <PageHeader :title="`Xin chào, ${auth.user?.name?.split(' ').slice(-1)[0] ?? ''}`" :description="`Tổng quan hoạt động của ${tenant.current?.name}`">
        <select v-model.number="days" class="h-8 rounded-md border bg-card px-2 text-xs text-muted-foreground shadow-xs" aria-label="Khoảng thời gian" data-testid="dashboard-range">
            <option :value="7">7 ngày qua</option>
            <option :value="30">30 ngày qua</option>
        </select>
    </PageHeader>

    <Card v-if="isNew && showStart" class="mb-5 overflow-hidden" data-testid="dashboard-welcome">
        <div class="flex flex-wrap items-center gap-5 bg-gradient-to-r from-blue-50 to-card to-70% px-5 py-5">
            <IconBubble :icon="Rocket" tone="blue" class="size-12" />
            <div class="min-w-0 flex-1">
                <p class="text-base font-bold">Bắt đầu bán hàng với EzSell</p>
                <p class="mt-0.5 text-sm text-muted-foreground">Làm 3 bước đầu là đã tạo đơn và in phiếu được. Các bước còn lại làm khi cần.</p>
                <div class="mt-2 h-1.5 w-full max-w-72 overflow-hidden rounded-full bg-slate-100"><i class="block h-full bg-emerald-500" :style="{ width: `${(progress.done / progress.total) * 100}%` }" /></div>
                <p class="mt-1 text-xs text-muted-foreground">Đã xong {{ progress.done }}/{{ progress.total }} bước</p>
            </div>
            <button type="button" class="text-xs text-muted-foreground hover:underline" @click="hideStart">Ẩn</button>
        </div>
        <div class="grid gap-3 px-5 pb-5 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="(st, n) in steps" :key="st.key" class="rounded-lg border bg-card px-4 py-3">
                <span :class="['grid size-6 place-items-center rounded-full text-xs font-bold', st.done ? 'bg-emerald-500 text-white' : 'bg-blue-50 text-blue-600']">
                    <CheckCircle2 v-if="st.done" class="size-4" /><template v-else>{{ n + 1 }}</template>
                </span>
                <p :class="['mt-2 text-sm font-semibold', st.done && 'text-muted-foreground']">
                    {{ st.label }}<span v-if="st.optional" class="ml-1.5 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-500">khi cần</span>
                </p>
                <p class="mt-0.5 min-h-8 text-xs text-muted-foreground">{{ st.detail }}</p>
                <RouterLink v-if="!st.done && st.to" :to="st.to" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">{{ st.action }} <ArrowRight class="size-3.5" /></RouterLink>
            </div>
        </div>
    </Card>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 space-y-5">
            <Card v-if="!isNew || !showStart" caps title="Kết quả kinh doanh hôm nay">
                <div class="grid divide-y sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-y-0" data-testid="dashboard-kpis">
                    <StatTile :icon="Banknote" tone="blue" label="Doanh thu" :value="orders ? formatMoney(orders.today_ordered_revenue) : null">
                        <template v-if="change !== null">
                            <span :class="change >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ change >= 0 ? '▲' : '▼' }} {{ Math.abs(change) }}%</span> so với hôm qua
                        </template>
                        <template v-else>Khách trả, chưa trừ phí sàn</template>
                    </StatTile>
                    <StatTile :icon="ShoppingCart" tone="green" label="Đơn hàng mới" :value="orders ? formatNumber(orders.today_orders) : null" :to="orders ? '/orders' : null">
                        {{ orders ? `${formatNumber(orders.to_process)} đơn chờ xử lý` : 'Chưa có dữ liệu' }}
                    </StatTile>
                    <StatTile :icon="Clock" tone="amber" label="Quá hạn giao" :value="orders ? formatNumber(orders.overdue) : null" :to="orders ? '/orders?flags=overdue' : null">
                        {{ orders ? `${formatNumber(orders.shortage)} đơn thiếu hàng` : 'Chưa có dữ liệu' }}
                    </StatTile>
                    <StatTile :icon="CircleX" tone="red" label="Đơn hủy hôm nay" :value="orders ? formatNumber(orders.today_cancelled) : null" :to="orders ? '/orders?tab=cancelled' : null">
                        {{ orders ? `${formatNumber(orders.today_returns)} đơn hoàn/trả` : 'Chưa có dữ liệu' }}
                    </StatTile>
                </div>
            </Card>

            <Card v-if="orders" caps title="Doanh thu bán hàng" :description="rangeLabel">
                <div class="px-5 pb-4 pt-5" data-testid="dashboard-revenue">
                    <template v-if="isNew">
                        <EmptyState :icon="BarChart3" title="Chưa có doanh thu" description="Biểu đồ sẽ hiện khi có đơn đầu tiên." class="py-6">
                            <div class="flex justify-center gap-2">
                                <RouterLink v-if="tenant.can('orders.create')" to="/orders/new"><Button><Plus /> Tạo đơn</Button></RouterLink>
                                <RouterLink v-if="tenant.can('orders.create')" to="/pos"><Button variant="outline"><Store /> Bán tại quầy</Button></RouterLink>
                            </div>
                        </EmptyState>
                    </template>
                    <template v-else>
                        <StackedBarChart v-if="chart.total > 0" :labels="chart.labels" :series="chart.series" />
                        <p v-else class="py-16 text-center text-sm text-muted-foreground">Chưa có doanh thu trong {{ rangeLabel }}.</p>
                        <p class="mt-3 text-center text-sm text-muted-foreground">Tổng doanh thu: <b class="tabular-nums text-foreground">{{ formatMoney(chart.total) }}</b></p>
                    </template>
                </div>
            </Card>

            <Card v-if="orders && (!isNew || !showStart)" caps title="Đơn hàng chờ xử lý">
                <div class="grid grid-cols-3 divide-x lg:grid-cols-6 max-lg:[&>*:nth-child(n+4)]:border-t" data-testid="dashboard-queues">
                    <RouterLink v-for="q in queues" :key="q.tab" :to="`/orders?tab=${q.tab}`" class="py-4 text-center hover:bg-muted/40">
                        <IconBubble :icon="q.icon" :tone="q.tone" class="mx-auto" />
                        <p class="mt-2 text-xs text-muted-foreground">{{ q.label }}</p>
                        <p :class="['text-lg font-bold tabular-nums', q.tone === 'red' && orders.queues[q.tab] ? toneClasses.red.value : '']">{{ formatNumber(orders.queues[q.tab] ?? 0) }}</p>
                    </RouterLink>
                </div>
            </Card>

            <div v-if="best || stock" class="grid gap-5 lg:grid-cols-2">
                <Card v-if="best" caps title="Top sản phẩm bán chạy" :description="rangeLabel">
                    <template #actions><RouterLink to="/reports" class="text-xs text-primary hover:underline">Báo cáo</RouterLink></template>
                    <ul v-if="best.length" class="divide-y text-sm" data-testid="dashboard-best">
                        <li v-for="(b, i) in best" :key="b.variant?.id ?? i" class="flex items-center gap-3 px-5 py-2.5">
                            <span :class="['grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-semibold', i < 3 ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-500']">{{ String(i + 1).padStart(2, '0') }}</span>
                            <div class="min-w-0 flex-1">
                                <RouterLink v-if="b.variant" :to="`/inventory/sku/${b.variant.id}`" class="block truncate hover:text-primary">{{ b.variant.name }}</RouterLink>
                                <span v-else class="text-muted-foreground">SKU đã xóa</span>
                                <div class="font-mono text-[11px] text-muted-foreground">{{ b.variant?.sku }}</div>
                            </div>
                            <b class="tabular-nums">{{ formatNumber(b.units) }}</b>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-10 text-center text-sm text-muted-foreground">Chưa bán được sản phẩm nào.</p>
                </Card>

                <Card v-if="stock" caps title="Thông tin kho">
                    <EmptyState v-if="isNew && !stock.on_hand" :icon="Package" title="Chưa có hàng trong kho" description="Thêm sản phẩm và nhập kho để theo dõi tồn và cảnh báo sắp hết hàng.">
                    <RouterLink v-if="tenant.can('products.view')" to="/products"><Button variant="outline">Thêm sản phẩm</Button></RouterLink>
                </EmptyState>
                <div v-else class="space-y-3 p-4" data-testid="dashboard-stock">
                        <RouterLink v-for="s in stockBoxes" :key="s.label" :to="s.to" :class="['flex items-center rounded-lg px-4 py-3 hover:brightness-[0.98]', toneClasses[s.tone].soft]">
                            <div>
                                <p class="flex items-center gap-1 text-xs text-muted-foreground">{{ s.label }} <CircleAlert v-if="s.alert" class="size-3.5 text-rose-400" /></p>
                                <p :class="['text-lg font-semibold tabular-nums', toneClasses[s.tone].value]">{{ s.value }}</p>
                            </div>
                            <ChevronRight class="ml-auto size-4 text-muted-foreground" />
                        </RouterLink>
                    </div>
                </Card>
            </div>
        </div>

        <div class="space-y-5">
            <Card v-if="share.length" caps title="Doanh thu theo kênh bán" :description="rangeLabel">
                <div class="p-5" data-testid="dashboard-share"><DonutChart :items="share" /></div>
            </Card>

            <Card v-if="!isNew && showStart && !connected" caps title="Bắt đầu" data-testid="dashboard-start">
                <template #actions><button type="button" class="text-xs text-muted-foreground hover:underline" @click="hideStart">Ẩn</button></template>
                <div class="px-5 pb-3">
                    <p class="text-xs text-muted-foreground">Đã xong {{ progress.done }}/{{ progress.total }} bước</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><i class="block h-full bg-emerald-500" :style="{ width: `${(progress.done / progress.total) * 100}%` }" /></div>
                </div>
                <ul class="divide-y border-t">
                    <li v-for="st in steps" :key="st.key" :class="['flex items-start gap-3 px-5 py-2.5 text-sm', !st.done && steps.find((x) => !x.done)?.key === st.key && 'bg-blue-50/40']">
                        <CheckCircle2 v-if="st.done" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                        <Circle v-else class="mt-0.5 size-4 shrink-0 text-slate-300" />
                        <div class="min-w-0 flex-1">
                            <p :class="st.done && 'text-muted-foreground line-through'">{{ st.label }}<span v-if="st.optional" class="ml-1.5 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-500">khi cần</span></p>
                            <p v-if="!st.done" class="text-xs text-muted-foreground">{{ st.detail }}</p>
                        </div>
                        <RouterLink v-if="!st.done && st.to" :to="st.to" class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline">{{ st.action }} <ArrowRight class="size-3.5" /></RouterLink>
                    </li>
                </ul>
            </Card>

            <Card v-if="accounts && (accounts.length || !showStart)" caps title="Gian hàng kết nối">
                <template #actions><RouterLink to="/marketplaces" class="text-xs text-primary hover:underline">Quản lý</RouterLink></template>
                <ul v-if="accounts.length" class="divide-y text-sm">
                    <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 px-5 py-2.5">
                        <MarketplaceLogo :marketplace="a.marketplace" :name="a.marketplace_name" />
                        <span class="min-w-0 flex-1 truncate">{{ a.shop_name }}</span>
                        <Badge :tone="accountTone(a)">{{ accountLabel(a) }}</Badge>
                    </li>
                </ul>
                <EmptyState v-else :icon="PlugZap" title="Chưa kết nối sàn" description="Kết nối Shopee, TikTok Shop, Lazada, Tiki để nhận đơn và đồng bộ tồn kho." />
            </Card>

            <Card v-if="orders?.recent.length" caps title="Đơn hàng gần đây">
                <template #actions><RouterLink to="/orders" class="text-xs text-primary hover:underline">Xem tất cả</RouterLink></template>
                <ol class="relative px-5 py-4 text-[13px] before:absolute before:bottom-6 before:left-[23px] before:top-6 before:border-l before:border-dashed before:border-blue-200" data-testid="recent-orders">
                    <li v-for="o in orders.recent" :key="o.id" class="relative pb-3.5 pl-6 last:pb-0">
                        <i class="absolute left-0 top-1.5 size-2 rounded-full bg-blue-400 ring-4 ring-blue-50" />
                        <MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" size="sm" class="mr-1.5 inline-block align-[-5px]" />
                        <RouterLink :to="`/orders/${o.id}`" class="font-mono text-xs font-medium text-primary hover:underline">{{ o.external_order_id }}</RouterLink>
                        <span class="text-xs text-muted-foreground"> · {{ marketplaceNames[o.marketplace] ?? o.marketplace }}</span>
                        <div class="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                            {{ formatDateTime(o.placed_at) }} · <span class="tabular-nums text-foreground">{{ formatMoney(o.total) }}</span>
                            <Badge :tone="orderStatus[o.status]?.tone" class="ml-auto px-1.5 py-0 text-[10px]">{{ orderStatus[o.status]?.label ?? o.status }}</Badge>
                        </div>
                    </li>
                </ol>
            </Card>

            <Card v-if="lowStock && lowStock.total" caps title="Cần nhập thêm" :description="`${formatNumber(lowStock.total)} SKU bằng hoặc dưới tồn an toàn`" data-testid="low-stock">
                <template v-if="tenant.can('purchasing.view')" #actions><RouterLink to="/purchasing/reorder" class="text-xs text-primary hover:underline">Gợi ý nhập</RouterLink></template>
                <ul class="divide-y text-sm">
                    <li v-for="i in lowStock.items.slice(0, 6)" :key="i.variant_id + i.warehouse">
                        <RouterLink :to="`/inventory/sku/${i.variant_id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-muted/30">
                            <div class="min-w-0 flex-1"><div class="font-mono text-xs font-medium">{{ i.sku }}</div><div class="truncate text-xs text-muted-foreground">{{ i.name }} · {{ i.warehouse }}</div></div>
                            <span :class="['whitespace-nowrap tabular-nums', i.available <= 0 ? 'font-medium text-rose-600' : 'text-amber-600']">{{ formatNumber(i.available) }} / {{ formatNumber(i.safety_stock) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
