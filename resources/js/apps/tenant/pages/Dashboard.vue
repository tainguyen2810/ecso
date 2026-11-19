<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import {
    AlarmClock,
    ArrowRight,
    BarChart3,
    Check,
    CheckCircle2,
    ChevronRight,
    Circle,
    CircleAlert,
    ClipboardCheck,
    Package,
    PackageOpen,
    PackageX,
    PlugZap,
    Plus,
    Rocket,
    Store,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import IconBubble from '@/shared/ui/IconBubble.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api } from '@/shared/lib/api';
import { toneClasses, type Tone } from '@/shared/lib/tones';
import { arrangeStartSteps, channelColors, channelLabel, MARKETPLACE_CHANNELS, sellerStartSteps, startProgress, type RevenueChannel, type StartStep } from './dashboard';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
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
// Each marketplace and each own sales channel (shop counter, Fanpage, Zalo...) is its own series.
const revenueChannels = computed(() => orders.value?.revenue_by_channel ?? []);
const colors = computed(() => channelColors(revenueChannels.value));
const chart = computed(() => {
    const rows = orders.value?.revenue_by_day ?? [];
    return {
        labels: rows.map((r, i) => (i === rows.length - 1 ? 'Hôm nay' : `${r.date.slice(8, 10)}/${r.date.slice(5, 7)}`)),
        series: revenueChannels.value.map((c) => ({ key: c.key, label: channelLabel(c, marketplaceNames), color: colors.value[c.key]!, values: rows.map((r) => Number(r.channels[c.key] ?? 0)) })),
        total: rows.reduce((s, r) => s + Number(r.revenue), 0),
    };
});
const share = computed(() => {
    const total = revenueChannels.value.reduce((s, c) => s + Number(c.revenue), 0) || 1;
    return revenueChannels.value.map((c) => ({ key: c.key, label: channelLabel(c, marketplaceNames), color: colors.value[c.key]!, value: Number(c.revenue), pct: Math.round((Number(c.revenue) / total) * 100) }));
});
const chartOrders = computed(() => (orders.value?.revenue_by_day ?? []).reduce((s, r) => s + r.orders, 0));

// "Cần làm": the few things to act on now, each with its own button. A zero card is shown greyed out.
const todo = computed(() => {
    const o = orders.value;
    if (!o) return [];
    const list: { key: string; label: string; count: number; unit: string; icon: Component; tone: Tone; to: string; action: string }[] = [
        { key: 'confirm', label: 'Chờ xác nhận', count: o.queues.to_confirm ?? 0, unit: 'đơn', icon: ClipboardCheck, tone: 'blue', to: '/orders?tab=to_confirm', action: 'Xác nhận' },
        { key: 'pack', label: 'Chờ đóng gói', count: o.queues.to_pack ?? 0, unit: 'đơn', icon: PackageOpen, tone: 'blue', to: tenant.can('orders.pack') ? '/orders/pack' : '/orders?tab=to_pack', action: 'Đóng gói' },
        { key: 'overdue', label: 'Quá hạn giao', count: o.overdue, unit: 'đơn', icon: AlarmClock, tone: 'red', to: '/orders?flags=overdue', action: 'Xem đơn' },
    ];
    if (stock.value) list.push({ key: 'low', label: 'Sắp hết hàng', count: stock.value.low_stock, unit: 'SKU', icon: Package, tone: 'amber', to: tenant.can('purchasing.view') ? '/purchasing/reorder' : '/inventory?state=low', action: 'Đặt nhập' });
    else list.push({ key: 'shortage', label: 'Thiếu hàng', count: o.shortage, unit: 'đơn', icon: PackageX, tone: 'amber', to: '/orders?flags=shortage', action: 'Xem đơn' });
    if (o.queues.unknown) list.push({ key: 'unknown', label: 'Cần kiểm tra', count: o.queues.unknown, unit: 'đơn', icon: TriangleAlert, tone: 'red', to: '/orders?tab=unknown', action: 'Kiểm tra' });
    return list;
});

const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 11 ? 'Chào buổi sáng' : h < 14 ? 'Chào buổi trưa' : h < 18 ? 'Chào buổi chiều' : 'Chào buổi tối';
});
const todayLabel = computed(() => {
    const d = new Date();
    const day = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'][d.getDay()];
    return `${day}, ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
});

/** A tiny line of the last 7 days (today last), drawn in a 100x28 box. */
function sparkline(values: number[]): string {
    const v = values.slice(-7);
    if (v.length < 2) return '';
    const max = Math.max(...v);
    const min = Math.min(...v);
    const span = max - min || 1;
    return v.map((x, i) => `${((i / (v.length - 1)) * 100).toFixed(1)},${(25 - ((x - min) / span) * 22).toFixed(1)}`).join(' ');
}
const pct = (now: number, before: number) => (before > 0 ? Math.round(((now - before) / before) * 100) : null);
const kpis = computed(() => {
    const o = orders.value;
    if (!o) return [];
    const rows = o.revenue_by_day;
    const revenue = rows.map((r) => Number(r.revenue));
    const count = rows.map((r) => r.orders);
    const avg = rows.map((r) => (r.orders ? Number(r.revenue) / r.orders : 0));
    const yOrders = rows.length > 1 ? rows[rows.length - 2]!.orders : 0;
    const yRevenue = Number(o.yesterday_ordered_revenue);
    const todayAvg = o.today_orders ? Number(o.today_ordered_revenue) / o.today_orders : 0;
    const yAvg = yOrders ? yRevenue / yOrders : 0;
    return [
        { key: 'revenue', label: 'Doanh thu hôm nay', value: formatMoney(o.today_ordered_revenue), delta: pct(Number(o.today_ordered_revenue), yRevenue), deltaText: null as string | null, line: sparkline(revenue), note: 'Khách trả, chưa trừ phí sàn', to: null as string | null },
        { key: 'orders', label: 'Đơn hàng mới', value: formatNumber(o.today_orders), delta: null, deltaText: yOrders || o.today_orders ? `${o.today_orders - yOrders >= 0 ? '+' : ''}${o.today_orders - yOrders} đơn` : null, up: o.today_orders >= yOrders, line: sparkline(count), note: `${formatNumber(o.to_process)} đơn đang chờ xử lý`, to: '/orders' },
        { key: 'avg', label: 'Giá trị TB / đơn', value: o.today_orders ? formatMoney(todayAvg) : '—', delta: o.today_orders ? pct(todayAvg, yAvg) : null, deltaText: null, line: sparkline(avg), note: 'so với hôm qua', to: null },
        { key: 'cancel', label: 'Hủy / hoàn hôm nay', value: `${formatNumber(o.today_cancelled)} / ${formatNumber(o.today_returns)}`, delta: null, deltaText: null, line: '', note: 'đơn hủy / đơn hoàn trả', to: '/orders?tab=cancelled' },
    ].map((k) => ({ ...k, up: 'up' in k ? (k as { up: boolean }).up : (k.delta ?? 0) >= 0 }));
});

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
const accountLabel = (a: MarketplaceAccountData) => (a.status !== 'connected' ? 'Đã ngắt' : a.circuit_open ? 'Tạm dừng' : a.last_error ? 'Cần xem' : 'Ổn');

/** "10 phút trước", "3 giờ trước", then the date. */
function ago(iso: string | null): string {
    if (!iso) return '';
    const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (min < 1) return 'vừa xong';
    if (min < 60) return `${min} phút trước`;
    if (min < 24 * 60) return `${Math.round(min / 60)} giờ trước`;
    const d = new Date(iso);
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}
const itemsLine = (o: OrderSummary) => (o.items_preview ?? []).map((i) => i.name).join(' · ');
const itemsQty = (o: OrderSummary) => (o.items_preview ?? []).reduce((s, i) => s + i.quantity, 0);

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
/**
 * Đợt 2: a shop that sells on Shopee, TikTok or a website gets 4 steps of its own (kết nối shop → liên kết sản phẩm
 * → nhập tồn đầu kỳ → xử lý đơn đầu tiên) at the top of the page instead of the general list.
 */
const sellerPath = computed(() => connected.value || !!tenant.current?.channels?.some((c) => MARKETPLACE_CHANNELS.includes(c)));
const sellerSteps = computed(() => sellerStartSteps({
    accounts: accounts.value ?? [],
    marketplaceNames,
    onHand: stock.value?.on_hand ?? 0,
    recentStatuses: (orders.value?.recent ?? []).map((o) => o.status),
    can: (p) => tenant.can(p),
}));
const sellerProgress = computed(() => startProgress(sellerSteps.value));
const showSeller = computed(() => sellerPath.value && !startHidden.value && accounts.value !== null && !!orders.value && !sellerProgress.value.finished);
/** A shop without any order yet: the page leads with the setup steps instead of empty charts. */
const isNew = computed(() => !!orders.value && !orders.value.recent.length);
const showStart = computed(() => !sellerPath.value && !startHidden.value && !progress.value.finished);
</script>

<template>
    <PageHeader :title="`${greeting}, ${auth.user?.name?.split(' ').slice(-1)[0] ?? ''}`">
        <template #description>
            {{ todayLabel }}<template v-if="orders"> · Hôm nay có <b class="text-foreground">{{ formatNumber(orders.to_process) }} đơn</b> cần xử lý</template>
        </template>
        <div class="inline-flex rounded-lg border bg-card p-0.5 shadow-xs" role="group" aria-label="Khoảng thời gian" data-testid="dashboard-range">
            <button v-for="d in [7, 30]" :key="d" type="button" :class="['h-7 rounded-md px-3 text-xs font-medium', days === d ? 'bg-slate-900 text-white' : 'text-muted-foreground hover:text-foreground']" @click="days = d">{{ d }} ngày</button>
        </div>
    </PageHeader>

    <Card v-if="showSeller" class="mb-5 overflow-hidden" data-testid="dashboard-seller-start">
        <div class="flex flex-wrap items-center gap-4 px-5 py-4">
            <div class="min-w-0 flex-1">
                <p class="text-base font-semibold">Bắt đầu với EzChat Bán hàng</p>
                <p class="text-sm text-muted-foreground">Xong 4 bước này là đơn và tồn kho tự chạy.</p>
            </div>
            <div class="w-48">
                <div class="mb-1 flex justify-between text-xs"><span class="text-muted-foreground">Đã xong</span><b>{{ sellerProgress.done }}/{{ sellerProgress.total }}</b></div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full bg-emerald-500" :style="{ width: `${(sellerProgress.done / sellerProgress.total) * 100}%` }" /></div>
            </div>
            <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted" aria-label="Ẩn" title="Ẩn" @click="hideStart"><X class="size-4" /></button>
        </div>
        <ul class="divide-y border-t">
            <li v-for="(st, n) in sellerSteps" :key="st.key" class="flex items-center gap-4 px-5 py-3.5" :data-testid="`seller-step-${st.key}`">
                <span v-if="st.done" class="grid size-7 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><Check class="size-4" /></span>
                <span v-else class="grid size-7 shrink-0 place-items-center rounded-full border-2 border-slate-300 text-xs font-semibold text-slate-500">{{ n + 1 }}</span>
                <div class="min-w-0 flex-1">
                    <p :class="['font-medium', st.done && 'text-muted-foreground line-through']">{{ st.label }}</p>
                    <p class="text-[13px] text-muted-foreground">{{ st.detail }}</p>
                </div>
                <span v-if="st.done" class="shrink-0 text-[13px] font-medium text-emerald-600">Đã xong</span>
                <RouterLink v-else-if="st.to" :to="st.to"><Button size="sm" :variant="sellerSteps.find((x) => !x.done)?.key === st.key ? 'default' : 'outline'">{{ st.action }}</Button></RouterLink>
            </li>
        </ul>
    </Card>

    <Card v-if="isNew && showStart" class="mb-5 overflow-hidden" data-testid="dashboard-welcome">
        <div class="flex flex-wrap items-center gap-5 bg-gradient-to-r from-blue-50 to-card to-70% px-5 py-5">
            <IconBubble :icon="Rocket" tone="blue" class="size-12" />
            <div class="min-w-0 flex-1">
                <p class="text-base font-bold">Bắt đầu bán hàng với EzChat Bán hàng</p>
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

    <template v-if="!isNew || !showStart">
        <div v-if="todo.length" :class="['mb-5 grid gap-3 sm:grid-cols-2', todo.length > 4 ? 'xl:grid-cols-5' : 'xl:grid-cols-4']" data-testid="dashboard-todo">
            <RouterLink
                v-for="t in todo"
                :key="t.key"
                :to="t.to"
                :class="['group flex items-center gap-3 rounded-xl border bg-card px-4 py-3.5 shadow-xs transition hover:border-primary/40 hover:shadow-sm', !t.count && 'opacity-60']"
            >
                <IconBubble :icon="t.icon" :tone="t.count ? t.tone : 'slate'" size="lg" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs text-muted-foreground">{{ t.label }}</p>
                    <p class="text-lg font-bold tabular-nums">{{ formatNumber(t.count) }} <span class="text-sm font-semibold">{{ t.unit }}</span></p>
                </div>
                <span v-if="t.count" class="inline-flex shrink-0 items-center gap-0.5 text-xs font-semibold text-primary group-hover:underline">{{ t.action }} <ChevronRight class="size-3.5" /></span>
                <CheckCircle2 v-else class="size-4 shrink-0 text-emerald-500" />
            </RouterLink>
        </div>

        <div class="mb-5 grid gap-px overflow-hidden rounded-xl border bg-border shadow-xs sm:grid-cols-2 xl:grid-cols-4" data-testid="dashboard-kpis">
            <component :is="k.to ? 'RouterLink' : 'div'" v-for="k in kpis" :key="k.key" :to="k.to ?? undefined" :class="['block bg-card px-5 py-4', k.to && 'hover:bg-slate-50']">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm text-muted-foreground">{{ k.label }}</p>
                    <span v-if="k.delta !== null || k.deltaText" :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums', k.up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">
                        <TrendingUp v-if="k.up" class="size-3" /><TrendingDown v-else class="size-3" />{{ k.deltaText ?? `${k.delta! >= 0 ? '+' : ''}${k.delta}%` }}
                    </span>
                </div>
                <div class="mt-1.5 flex items-end justify-between gap-3">
                    <p class="whitespace-nowrap text-2xl font-bold tabular-nums tracking-tight">{{ k.value }}</p>
                    <svg v-if="k.line" viewBox="0 0 100 28" class="mb-1 h-7 w-24 shrink-0" preserveAspectRatio="none" aria-hidden="true">
                        <polyline :points="k.line" fill="none" :class="k.up ? 'stroke-emerald-500' : 'stroke-rose-500'" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    </svg>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">{{ k.note }}</p>
            </component>
        </div>
    </template>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0 space-y-5">
            <Card v-if="orders" :caps="false" :title="`Doanh thu ${days} ngày`">
                <div class="px-5 pb-4 pt-4" data-testid="dashboard-revenue">
                    <template v-if="isNew">
                        <EmptyState :icon="BarChart3" title="Chưa có doanh thu" description="Biểu đồ sẽ hiện khi có đơn đầu tiên." class="py-6">
                            <div class="flex justify-center gap-2">
                                <RouterLink v-if="tenant.can('orders.create')" to="/orders/new"><Button><Plus /> Tạo đơn</Button></RouterLink>
                                <RouterLink v-if="tenant.can('orders.create')" to="/pos"><Button variant="outline"><Store /> Bán tại quầy</Button></RouterLink>
                            </div>
                        </EmptyState>
                    </template>
                    <template v-else>
                        <p class="mb-3 text-sm text-muted-foreground">Tổng <b class="tabular-nums text-foreground">{{ formatMoney(chart.total) }}</b> · {{ formatNumber(chartOrders) }} đơn</p>
                        <StackedBarChart v-if="chart.total > 0" :labels="chart.labels" :series="chart.series" :height="260" />
                        <p v-else class="py-16 text-center text-sm text-muted-foreground">Chưa có doanh thu trong {{ rangeLabel }}.</p>
                    </template>
                </div>
            </Card>

            <Card v-if="orders?.recent.length" :caps="false" title="Đơn mới nhất">
                <template #actions><RouterLink to="/orders" class="text-sm font-medium text-primary hover:underline">Tất cả đơn</RouterLink></template>
                <ul class="divide-y text-sm" data-testid="recent-orders">
                    <li v-for="o in orders.recent" :key="o.id">
                        <RouterLink :to="`/orders/${o.id}`" class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-muted/30 md:grid-cols-[auto_minmax(0,14rem)_minmax(0,1fr)_auto_7.5rem]">
                            <MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" />
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ o.buyer_name || 'Khách lẻ' }}</p>
                                <p class="truncate text-xs text-muted-foreground"><span class="font-mono">{{ o.external_order_id }}</span> · {{ ago(o.placed_at) }}</p>
                            </div>
                            <div class="hidden min-w-0 md:block">
                                <p class="truncate">{{ itemsLine(o) || '—' }}</p>
                                <p v-if="itemsQty(o)" class="text-xs text-muted-foreground">{{ itemsQty(o) }} sp</p>
                            </div>
                            <b class="whitespace-nowrap text-right tabular-nums">{{ formatMoney(o.total) }}</b>
                            <div class="col-span-3 md:col-span-1 md:text-right">
                                <Badge :tone="orderStatus[o.status]?.tone" class="whitespace-nowrap">{{ orderStatus[o.status]?.label ?? o.status }}</Badge>
                            </div>
                        </RouterLink>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="space-y-5">
            <Card v-if="share.length" :caps="false" title="Theo kênh bán" :description="rangeLabel">
                <ul class="space-y-3.5 px-5 py-5" data-testid="dashboard-share">
                    <li v-for="c in share" :key="c.key">
                        <div class="flex items-baseline gap-2 text-sm">
                            <i class="size-2 shrink-0 rounded-full" :style="{ background: c.color }" />
                            <span class="min-w-0 flex-1 truncate">{{ c.label }}</span>
                            <b class="tabular-nums">{{ formatMoney(c.value) }}</b>
                            <span class="w-9 text-right text-xs tabular-nums text-muted-foreground">{{ c.pct }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full" :style="{ width: `${c.pct}%`, background: c.color }" /></div>
                    </li>
                </ul>
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

            <Card v-if="best" :caps="false" title="Bán chạy" :description="rangeLabel">
                <template #actions><RouterLink to="/reports" class="text-sm font-medium text-primary hover:underline">Báo cáo</RouterLink></template>
                <ul v-if="best.length" class="divide-y text-sm" data-testid="dashboard-best">
                    <li v-for="(b, i) in best" :key="b.variant?.id ?? i" class="flex items-center gap-3 px-5 py-2.5">
                        <span :class="['w-4 shrink-0 text-center text-xs font-semibold tabular-nums', i < 3 ? 'text-primary' : 'text-muted-foreground']">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <RouterLink v-if="b.variant" :to="`/inventory/sku/${b.variant.id}`" class="block truncate font-medium hover:text-primary">{{ b.variant.name }}</RouterLink>
                            <span v-else class="text-muted-foreground">SKU đã xóa</span>
                            <p class="text-xs text-muted-foreground"><span class="font-mono">{{ b.variant?.sku }}</span> · {{ formatNumber(b.units) }} đã bán</p>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-5 py-10 text-center text-sm text-muted-foreground">Chưa bán được sản phẩm nào.</p>
            </Card>

            <Card v-if="accounts && (accounts.length || !showStart)" :caps="false" title="Gian hàng">
                <template #actions><RouterLink to="/marketplaces" class="text-sm font-medium text-primary hover:underline">Quản lý</RouterLink></template>
                <ul v-if="accounts.length" class="divide-y text-sm">
                    <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 px-5 py-2.5">
                        <MarketplaceLogo :marketplace="a.marketplace" :name="a.marketplace_name" />
                        <span class="min-w-0 flex-1 truncate font-medium">{{ a.shop_name }}</span>
                        <Badge :tone="accountTone(a)">{{ accountLabel(a) }}</Badge>
                    </li>
                </ul>
                <EmptyState v-else :icon="PlugZap" title="Chưa kết nối sàn" description="Kết nối Shopee, TikTok Shop, Lazada, Tiki để nhận đơn và đồng bộ tồn kho." />
            </Card>

            <Card v-if="stock" :caps="false" title="Kho hàng">
                <template #actions><RouterLink to="/inventory" class="text-sm font-medium text-primary hover:underline">Quản lý kho</RouterLink></template>
                <EmptyState v-if="isNew && !stock.on_hand" :icon="Package" title="Chưa có hàng trong kho" description="Thêm sản phẩm và nhập kho để theo dõi tồn và cảnh báo sắp hết hàng.">
                    <RouterLink v-if="tenant.can('products.view')" to="/products"><Button variant="outline">Thêm sản phẩm</Button></RouterLink>
                </EmptyState>
                <ul v-else class="divide-y text-sm" data-testid="dashboard-stock">
                    <li v-for="s in stockBoxes" :key="s.label">
                        <RouterLink :to="s.to" class="flex items-center gap-2 px-5 py-2.5 hover:bg-muted/30">
                            <span class="flex-1 text-muted-foreground">{{ s.label }}</span>
                            <CircleAlert v-if="s.alert" class="size-3.5 text-rose-400" />
                            <b :class="['tabular-nums', s.alert ? 'text-rose-600' : '']">{{ s.value }}</b>
                            <ChevronRight class="size-4 text-muted-foreground" />
                        </RouterLink>
                    </li>
                </ul>
            </Card>

            <Card v-if="lowStock && lowStock.total" :caps="false" title="Cần nhập thêm" :description="`${formatNumber(lowStock.total)} SKU bằng hoặc dưới tồn an toàn`" data-testid="low-stock">
                <template v-if="tenant.can('purchasing.view')" #actions><RouterLink to="/purchasing/reorder" class="text-sm font-medium text-primary hover:underline">Gợi ý nhập</RouterLink></template>
                <ul class="divide-y text-sm">
                    <li v-for="i in lowStock.items.slice(0, 5)" :key="i.variant_id + i.warehouse">
                        <RouterLink :to="`/inventory/sku/${i.variant_id}`" class="flex items-center gap-3 px-5 py-2.5 hover:bg-muted/30">
                            <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ i.name }}</div><div class="truncate text-xs text-muted-foreground"><span class="font-mono">{{ i.sku }}</span> · {{ i.warehouse }}</div></div>
                            <span :class="['whitespace-nowrap tabular-nums', i.available <= 0 ? 'font-medium text-rose-600' : 'text-amber-600']">{{ formatNumber(i.available) }} / {{ formatNumber(i.safety_stock) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
