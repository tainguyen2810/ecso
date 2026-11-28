<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import { computed, onMounted, ref, watch } from 'vue';
import {
    AlarmClock,
    ArrowDown,
    ArrowRight,
    ArrowUp,
    BarChart3,
    Check,
    CheckCircle2,
    ChevronRight,
    Circle,
    ClipboardCheck,
    HandCoins,
    Package,
    PackageOpen,
    PackageX,
    PlugZap,
    Plus,
    Receipt,
    Rocket,
    ShoppingCart,
    Store,
    TriangleAlert,
    Undo2,
    Wallet,
    X,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ReportKpi from './reports/ReportKpi.vue';
import ReportChip from './reports/ReportChip.vue';
import SideCard from './reports/SideCard.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api } from '@/shared/lib/api';
import type { Tone } from '@/shared/lib/tones';
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
    payout: { received: string; orders: number; pending: string; pending_orders: number } | null;
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

/** Headline figures of today against yesterday (mẫu Tailwind 28/11: the first card is the blue one). */
const delta = (now: number, before: number) => (before > 0 ? ((now - before) / before) * 100 : null);
const head = computed(() => {
    const o = orders.value;
    if (!o) return null;
    const rows = o.revenue_by_day;
    const yOrders = rows.length > 1 ? rows[rows.length - 2]!.orders : 0;
    const revenue = Number(o.today_ordered_revenue);
    const yRevenue = Number(o.yesterday_ordered_revenue);
    const avg = o.today_orders ? revenue / o.today_orders : null;
    const yAvg = yOrders ? yRevenue / yOrders : 0;
    return {
        revenue,
        revenueDelta: delta(revenue, yRevenue),
        orders: o.today_orders,
        ordersDiff: yOrders || o.today_orders ? o.today_orders - yOrders : null,
        avg,
        avgDelta: avg !== null ? delta(avg, yAvg) : null,
    };
});
/** Decoration of the blue card: revenue per day of the range as a soft area. */
const spark = computed(() => {
    const v = (orders.value?.revenue_by_day ?? []).map((r) => Number(r.revenue));
    const max = Math.max(...v, 0);
    if (v.length < 2 || max <= 0) return null;
    return `M0,40 L${v.map((x, i) => `${(i / (v.length - 1)) * 100},${40 - (x / max) * 36}`).join(' L')} L100,40 Z`;
});
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
const todoTones: Record<string, string> = { blue: 'bg-blue-50 text-blue-600', red: 'bg-red-50 text-red-500', amber: 'bg-amber-50 text-amber-600', green: 'bg-emerald-50 text-emerald-600', slate: 'bg-slate-100 text-slate-500' };
const pctText = (v: number) => `${Math.abs(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;

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
const itemsLine = (o: OrderSummary) => (o.items_preview ?? []).map((i) => (i.variant_name ? `[${i.variant_name}] ${i.name}` : i.name)).join(' · ');
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
    <div class="mx-auto max-w-[1600px]">
        <!-- Header like the report pages: big title, the day and what is waiting, period switch and quick actions. -->
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="text-[14px] text-slate-500">{{ todayLabel }}</p>
                <h1 class="mt-1 text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">{{ greeting }}, {{ auth.user?.name?.split(' ').slice(-1)[0] ?? '' }}</h1>
                <p v-if="orders" class="mt-1.5 text-[15px] text-slate-500">Hôm nay có <b class="font-semibold text-slate-900">{{ formatNumber(orders.to_process) }} đơn</b> cần xử lý</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-[12px] bg-slate-100 p-1" role="group" aria-label="Khoảng thời gian" data-testid="dashboard-range">
                    <button v-for="d in [7, 30]" :key="d" type="button" :class="['h-9 rounded-[9px] px-4 text-[14px] font-medium transition', days === d ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800']" @click="days = d">{{ d }} ngày</button>
                </div>
                <RouterLink v-if="tenant.can('reports.view')" to="/reports/group/sales" class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 bg-white px-4 text-[14px] font-medium text-slate-700 hover:bg-slate-50"><BarChart3 class="size-4" />Xem báo cáo</RouterLink>
            </div>
        </header>

        <section v-if="showSeller" :class="[card, 'mb-6 overflow-hidden']" data-testid="dashboard-seller-start">
            <div class="flex flex-wrap items-center gap-4 bg-gradient-to-r from-blue-50 via-white to-white px-6 py-5">
                <span class="grid size-11 shrink-0 place-items-center rounded-[12px] bg-blue-600 text-white"><Rocket class="size-5" /></span>
                <div class="min-w-[12rem] flex-1">
                    <p class="text-[17px] font-semibold text-slate-900">Bắt đầu với EzChat Bán hàng</p>
                    <p class="text-[14px] text-slate-500">Xong 4 bước này là đơn và tồn kho tự chạy.</p>
                </div>
                <div class="order-last w-full sm:order-none sm:w-52">
                    <div class="mb-1.5 flex justify-between text-[13px]"><span class="text-slate-500">Đã xong</span><b class="text-slate-900">{{ sellerProgress.done }}/{{ sellerProgress.total }}</b></div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full bg-emerald-500" :style="{ width: `${(sellerProgress.done / sellerProgress.total) * 100}%` }" /></div>
                </div>
                <button type="button" class="rounded-[9px] p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Ẩn" title="Ẩn" @click="hideStart"><X class="size-4" /></button>
            </div>
            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                <li v-for="(st, n) in sellerSteps" :key="st.key" class="flex items-center gap-4 px-6 py-4" :data-testid="`seller-step-${st.key}`">
                    <span v-if="st.done" class="grid size-8 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><Check class="size-4" /></span>
                    <span v-else class="grid size-8 shrink-0 place-items-center rounded-full border-2 border-slate-200 text-[13px] font-semibold text-slate-500">{{ n + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <p :class="['text-[15px] font-medium', st.done ? 'text-slate-400 line-through' : 'text-slate-900']">{{ st.label }}</p>
                        <p class="text-[13px] text-slate-500">{{ st.detail }}</p>
                    </div>
                    <span v-if="st.done" class="shrink-0 rounded-[8px] bg-emerald-50 px-2.5 py-1 text-[13px] font-medium text-emerald-700">Đã xong</span>
                    <RouterLink
                        v-else-if="st.to"
                        :to="st.to"
                        :class="['inline-flex h-9 shrink-0 items-center rounded-[10px] px-4 text-[14px] font-medium', sellerSteps.find((x) => !x.done)?.key === st.key ? 'bg-blue-600 text-white hover:bg-blue-700' : 'border border-slate-200 text-slate-700 hover:bg-slate-50']"
                    >{{ st.action }}</RouterLink>
                </li>
            </ul>
        </section>

        <section v-if="isNew && showStart" :class="[card, 'mb-6 overflow-hidden']" data-testid="dashboard-welcome">
            <div class="flex flex-wrap items-center gap-5 bg-gradient-to-r from-blue-50 via-white to-white px-6 py-6">
                <span class="grid size-12 shrink-0 place-items-center rounded-[13px] bg-blue-600 text-white"><Rocket class="size-6" /></span>
                <div class="min-w-0 flex-1">
                    <p class="text-[18px] font-bold text-slate-900">Bắt đầu bán hàng với EzChat Bán hàng</p>
                    <p class="mt-0.5 text-[14px] text-slate-500">Làm 3 bước đầu là đã tạo đơn và in phiếu được. Các bước còn lại làm khi cần.</p>
                    <div class="mt-3 h-2 w-full max-w-72 overflow-hidden rounded-full bg-slate-100"><i class="block h-full bg-emerald-500" :style="{ width: `${(progress.done / progress.total) * 100}%` }" /></div>
                    <p class="mt-1.5 text-[13px] text-slate-500">Đã xong {{ progress.done }}/{{ progress.total }} bước</p>
                </div>
                <button type="button" class="text-[13px] text-slate-500 hover:underline" @click="hideStart">Ẩn</button>
            </div>
            <div class="grid gap-4 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="(st, n) in steps" :key="st.key" class="rounded-[12px] border border-slate-200 bg-white px-4 py-4">
                    <span :class="['grid size-7 place-items-center rounded-full text-[13px] font-bold', st.done ? 'bg-emerald-500 text-white' : 'bg-blue-50 text-blue-600']">
                        <Check v-if="st.done" class="size-4" /><template v-else>{{ n + 1 }}</template>
                    </span>
                    <p :class="['mt-2.5 text-[15px] font-semibold', st.done ? 'text-slate-400' : 'text-slate-900']">
                        {{ st.label }}<span v-if="st.optional" class="ml-1.5 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-500">khi cần</span>
                    </p>
                    <p class="mt-0.5 min-h-8 text-[13px] text-slate-500">{{ st.detail }}</p>
                    <RouterLink v-if="!st.done && st.to" :to="st.to" class="mt-2 inline-flex items-center gap-1 text-[13px] font-semibold text-blue-600 hover:underline">{{ st.action }} <ArrowRight class="size-3.5" /></RouterLink>
                </div>
            </div>
        </section>

        <template v-if="!isNew || !showStart">
            <h2 v-if="todo.length" class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Cần làm ngay</h2>
            <div v-if="todo.length" :class="['mb-6 grid grid-cols-2 gap-3 sm:gap-4', todo.length > 4 ? 'xl:grid-cols-5' : 'xl:grid-cols-4']" data-testid="dashboard-todo">
                <RouterLink
                    v-for="t in todo"
                    :key="t.key"
                    :to="t.to"
                    :class="[card, 'group block px-4 py-4 transition sm:px-5 hover:border-blue-300', !t.count && 'opacity-60']"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span :class="['grid size-10 shrink-0 place-items-center rounded-[11px]', t.count ? todoTones[t.tone] : 'bg-slate-100 text-slate-500']"><component :is="t.icon" class="size-5" :stroke-width="1.9" /></span>
                        <span v-if="t.count" class="inline-flex shrink-0 items-center gap-0.5 rounded-[9px] bg-blue-50 px-2.5 py-1.5 text-[13px] font-semibold text-blue-700 group-hover:bg-blue-100">{{ t.action }} <ChevronRight class="size-3.5" /></span>
                        <CheckCircle2 v-else class="size-5 shrink-0 text-emerald-500" />
                    </div>
                    <p class="mt-3 text-[14px] text-slate-500">{{ t.label }}</p>
                    <p class="text-[24px] font-bold leading-tight tabular-nums text-slate-900">{{ formatNumber(t.count) }} <span class="text-[14px] font-semibold text-slate-400">{{ t.unit }}</span></p>
                </RouterLink>
            </div>

            <h2 v-if="head" class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Hôm nay</h2>
            <div v-if="head && orders" class="mb-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="dashboard-kpis">
                <ReportKpi primary label="Doanh thu hôm nay" tip="Tiền khách trả trên các đơn đặt hôm nay, chưa trừ phí sàn." :icon="Wallet" :value="head.revenue">
                    <template v-if="head.revenueDelta !== null" #chips>
                        <ReportChip tone="onblue"><component :is="head.revenueDelta >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pctText(head.revenueDelta) }}</ReportChip>
                        <span class="text-blue-100">so với hôm qua</span>
                    </template>
                    Khách trả, chưa trừ phí sàn
                    <template #decor>
                        <svg v-if="spark" viewBox="0 0 100 40" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-12 w-full opacity-[0.12]" aria-hidden="true"><path :d="spark" fill="white" /></svg>
                    </template>
                </ReportKpi>
                <RouterLink to="/orders" class="block rounded-[14px] transition hover:ring-2 hover:ring-blue-100">
                    <ReportKpi label="Đơn hàng mới" tip="Đơn đặt hôm nay trên mọi kênh." :icon="ShoppingCart" tone="blue" plain unit="đơn" :value="head.orders" class="h-full">
                        <template v-if="head.ordersDiff !== null" #chips>
                            <ReportChip :tone="head.ordersDiff >= 0 ? 'up' : 'down'"><component :is="head.ordersDiff >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ head.ordersDiff >= 0 ? '+' : '' }}{{ head.ordersDiff }} đơn</ReportChip>
                            <span class="text-slate-500">so với hôm qua</span>
                        </template>
                        {{ formatNumber(orders.to_process) }} đơn đang chờ xử lý
                    </ReportKpi>
                </RouterLink>
                <ReportKpi label="Giá trị TB / đơn" tip="Doanh thu hôm nay chia số đơn hôm nay." :icon="Receipt" tone="violet" plain :value="head.avg === null ? null : Math.round(head.avg)">
                    <template v-if="head.avgDelta !== null" #chips>
                        <ReportChip :tone="head.avgDelta >= 0 ? 'up' : 'down'"><component :is="head.avgDelta >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pctText(head.avgDelta) }}</ReportChip>
                        <span class="text-slate-500">so với hôm qua</span>
                    </template>
                    {{ head.avg === null ? 'Chưa có đơn hôm nay' : 'Doanh thu hôm nay chia số đơn' }}
                </ReportKpi>
                <RouterLink v-if="orders.payout" to="/finance" class="block rounded-[14px] transition hover:ring-2 hover:ring-blue-100">
                    <ReportKpi :label="`Thực nhận ${days} ngày`" tip="Tiền sàn đã chốt trả cho shop trong khoảng đã chọn." :icon="HandCoins" tone="green" :value="Number(orders.payout.received)" class="h-full">
                        <template v-if="orders.payout.pending_orders" #chips><ReportChip tone="amber">+ {{ formatMoney(orders.payout.pending) }} tạm tính</ReportChip></template>
                        {{ orders.payout.pending_orders ? `${formatNumber(orders.payout.pending_orders)} đơn sàn chưa chốt tiền` : `${formatNumber(orders.payout.orders)} đơn sàn đã chốt tiền` }}
                    </ReportKpi>
                </RouterLink>
                <RouterLink v-else to="/orders?tab=cancelled" class="block rounded-[14px] transition hover:ring-2 hover:ring-blue-100">
                    <ReportKpi label="Hủy / hoàn hôm nay" :icon="Undo2" :tone="orders.today_cancelled + orders.today_returns ? 'red' : 'slate'" unit="đơn hủy" :value="orders.today_cancelled" class="h-full">
                        <template #chips><ReportChip :tone="orders.today_returns ? 'amber' : 'plain'">{{ formatNumber(orders.today_returns) }} đơn hoàn trả</ReportChip></template>
                        Đơn hủy và đơn khách trả hôm nay
                    </ReportKpi>
                </RouterLink>
            </div>
        </template>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-6">
                <section v-if="orders" :class="card">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-6 py-5">
                        <div>
                            <h3 class="text-[17px] font-semibold text-slate-900">Doanh thu {{ days }} ngày</h3>
                            <p class="mt-0.5 text-[14px] text-slate-500">Theo ngày đặt, tách theo kênh bán · {{ formatNumber(chartOrders) }} đơn</p>
                        </div>
                        <p v-if="!isNew" class="whitespace-nowrap text-[26px] font-bold leading-none tabular-nums tracking-tight text-slate-900">{{ formatNumber(chart.total) }}<span class="ml-1 text-[15px] font-semibold text-slate-400">đ</span></p>
                    </header>
                    <div class="px-6 pb-5 pt-4" data-testid="dashboard-revenue">
                        <template v-if="isNew">
                            <EmptyState :icon="BarChart3" title="Chưa có doanh thu" description="Biểu đồ sẽ hiện khi có đơn đầu tiên." class="py-6">
                                <div class="flex justify-center gap-2">
                                    <RouterLink v-if="tenant.can('orders.create')" to="/orders/new"><Button><Plus /> Tạo đơn</Button></RouterLink>
                                    <RouterLink v-if="tenant.can('orders.create')" to="/pos"><Button variant="outline"><Store /> Bán tại quầy</Button></RouterLink>
                                </div>
                            </EmptyState>
                        </template>
                        <template v-else>
                            <StackedBarChart v-if="chart.total > 0" :labels="chart.labels" :series="chart.series" :height="280" />
                            <p v-else class="py-16 text-center text-[14px] text-slate-500">Chưa có doanh thu trong {{ rangeLabel }}.</p>
                        </template>
                    </div>
                </section>

                <section v-if="orders?.recent.length" :class="[card, 'overflow-hidden']">
                    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                        <div>
                            <h3 class="text-[17px] font-semibold text-slate-900">Đơn mới nhất</h3>
                            <p class="mt-0.5 text-[14px] text-slate-500">Các đơn vừa đặt trên mọi kênh</p>
                        </div>
                        <RouterLink to="/orders" class="inline-flex items-center gap-1 rounded-[10px] border border-slate-200 px-3 py-1.5 text-[14px] font-medium text-slate-700 hover:bg-slate-50">Tất cả đơn <ArrowRight class="size-4" /></RouterLink>
                    </header>
                    <ul class="divide-y divide-slate-100 text-[14px]" data-testid="recent-orders">
                        <li v-for="o in orders.recent" :key="o.id">
                            <RouterLink :to="`/orders/${o.id}`" class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-6 py-3.5 hover:bg-slate-50 md:grid-cols-[auto_minmax(0,14rem)_minmax(0,1fr)_auto_8rem]">
                                <MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" size="lg" />
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900">{{ o.buyer_name || 'Khách lẻ' }}</p>
                                    <p class="truncate text-[12px] text-slate-500"><span class="font-mono">{{ orderCode(o) }}</span> · {{ ago(o.placed_at) }}</p>
                                </div>
                                <div class="hidden min-w-0 md:block">
                                    <p class="truncate text-slate-700">{{ itemsLine(o) || '—' }}</p>
                                    <p v-if="itemsQty(o)" class="text-[12px] text-slate-500">{{ itemsQty(o) }} sản phẩm</p>
                                </div>
                                <b class="whitespace-nowrap text-right font-semibold tabular-nums text-slate-900">{{ formatMoney(o.total) }}</b>
                                <div class="col-span-3 md:col-span-1 md:text-right">
                                    <Badge :tone="orderStatus[o.status]?.tone" class="whitespace-nowrap">{{ orderStatus[o.status]?.label ?? o.status }}</Badge>
                                </div>
                            </RouterLink>
                        </li>
                    </ul>
                </section>
            </div>

            <aside class="space-y-5">
                <SideCard v-if="share.length" title="Theo kênh bán" :description="`Doanh thu ${rangeLabel}`">
                    <ul class="space-y-4 px-5 py-5" data-testid="dashboard-share">
                        <li v-for="c in share" :key="c.key">
                            <div class="flex items-center gap-2.5 text-[14px]">
                                <i class="size-2.5 shrink-0 rounded-full" :style="{ background: c.color }" />
                                <span class="min-w-0 flex-1 truncate text-slate-700">{{ c.label }}</span>
                                <b class="font-semibold tabular-nums text-slate-900">{{ formatNumber(c.value) }}</b>
                                <span class="w-10 text-right text-[12px] tabular-nums text-slate-500">{{ c.pct }}%</span>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full" :style="{ width: `${c.pct}%`, background: c.color }" /></div>
                        </li>
                    </ul>
                </SideCard>

                <SideCard v-if="!isNew && showStart && !connected" title="Bắt đầu" :description="`Đã xong ${progress.done}/${progress.total} bước`" data-testid="dashboard-start">
                    <template #actions><button type="button" class="text-[13px] text-slate-500 hover:underline" @click="hideStart">Ẩn</button></template>
                    <div class="px-5 pt-4"><div class="h-2 overflow-hidden rounded-full bg-slate-100"><i class="block h-full bg-emerald-500" :style="{ width: `${(progress.done / progress.total) * 100}%` }" /></div></div>
                    <ul class="divide-y divide-slate-100 px-5 py-2">
                        <li v-for="st in steps" :key="st.key" class="flex items-start gap-3 py-2.5 text-[14px]">
                            <CheckCircle2 v-if="st.done" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                            <Circle v-else class="mt-0.5 size-4 shrink-0 text-slate-300" />
                            <div class="min-w-0 flex-1">
                                <p :class="st.done ? 'text-slate-400 line-through' : 'text-slate-800'">{{ st.label }}<span v-if="st.optional" class="ml-1.5 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold text-slate-500">khi cần</span></p>
                                <p v-if="!st.done" class="text-[12px] text-slate-500">{{ st.detail }}</p>
                            </div>
                            <RouterLink v-if="!st.done && st.to" :to="st.to" class="inline-flex shrink-0 items-center gap-1 text-[13px] font-medium text-blue-600 hover:underline">{{ st.action }} <ArrowRight class="size-3.5" /></RouterLink>
                        </li>
                    </ul>
                </SideCard>

                <SideCard v-if="best" title="Bán chạy" :description="rangeLabel">
                    <template #actions><RouterLink to="/reports/group/sales" class="rounded-[9px] border border-slate-200 px-3 py-1 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Báo cáo</RouterLink></template>
                    <ul v-if="best.length" class="divide-y divide-slate-100 px-5" data-testid="dashboard-best">
                        <li v-for="(b, i) in best" :key="b.variant?.id ?? i" class="flex items-center gap-3 py-3">
                            <span :class="['grid size-7 shrink-0 place-items-center rounded-[8px] text-[12px] font-bold tabular-nums', i < 3 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500']">{{ i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <RouterLink v-if="b.variant" :to="`/inventory/sku/${b.variant.id}`" class="block truncate text-[14px] font-medium text-slate-800 hover:text-blue-600">{{ b.variant.name }}</RouterLink>
                                <span v-else class="text-[14px] text-slate-500">SKU đã xóa</span>
                                <p class="truncate font-mono text-[11px] text-slate-500">{{ b.variant?.sku }}</p>
                            </div>
                            <span class="whitespace-nowrap text-[14px] font-semibold tabular-nums text-slate-900">{{ formatNumber(b.units) }} <span class="text-[12px] font-normal text-slate-500">đã bán</span></span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-10 text-center text-[14px] text-slate-500">Chưa bán được sản phẩm nào.</p>
                </SideCard>

                <SideCard v-if="stock" title="Kho hàng" description="Tồn kho hiện tại">
                    <template #actions><RouterLink to="/inventory" class="rounded-[9px] border border-slate-200 px-3 py-1 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Quản lý kho</RouterLink></template>
                    <EmptyState v-if="isNew && !stock.on_hand" :icon="Package" title="Chưa có hàng trong kho" description="Thêm sản phẩm và nhập kho để theo dõi tồn và cảnh báo sắp hết hàng.">
                        <RouterLink v-if="tenant.can('products.view')" to="/products"><Button variant="outline">Thêm sản phẩm</Button></RouterLink>
                    </EmptyState>
                    <ul v-else class="grid grid-cols-2 gap-3 p-5 text-[14px]" data-testid="dashboard-stock">
                        <li v-for="s in stockBoxes" :key="s.label">
                            <RouterLink :to="s.to" class="block rounded-[12px] bg-slate-50 px-4 py-3 hover:bg-slate-100">
                                <p class="text-[12px] leading-snug text-slate-500">{{ s.label }}</p>
                                <p :class="['mt-1 text-[18px] font-bold tabular-nums', s.alert ? 'text-red-600' : s.tone === 'amber' && s.value !== '0' ? 'text-amber-600' : 'text-slate-900']">{{ s.value }}</p>
                            </RouterLink>
                        </li>
                    </ul>
                </SideCard>

                <SideCard v-if="lowStock && lowStock.total" title="Cần nhập thêm" :description="`${formatNumber(lowStock.total)} SKU bằng hoặc dưới tồn an toàn`" data-testid="low-stock">
                    <template v-if="tenant.can('purchasing.view')" #actions><RouterLink to="/purchasing/reorder" class="whitespace-nowrap rounded-[9px] bg-blue-600 px-3 py-1 text-[13px] font-medium text-white hover:bg-blue-700">Gợi ý nhập</RouterLink></template>
                    <ul class="divide-y divide-slate-100 px-5">
                        <li v-for="i in lowStock.items.slice(0, 5)" :key="i.variant_id + i.warehouse">
                            <RouterLink :to="`/inventory/sku/${i.variant_id}`" class="flex items-center gap-3 py-3 hover:text-blue-600">
                                <div class="min-w-0 flex-1"><div class="truncate text-[14px] font-medium text-slate-800">{{ i.name }}</div><div class="truncate text-[12px] text-slate-500"><span class="font-mono">{{ i.sku }}</span> · {{ i.warehouse }}</div></div>
                                <span :class="['whitespace-nowrap rounded-[8px] px-2 py-0.5 text-[13px] font-semibold tabular-nums', i.available <= 0 ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700']">{{ formatNumber(i.available) }} / {{ formatNumber(i.safety_stock) }}</span>
                            </RouterLink>
                        </li>
                    </ul>
                </SideCard>

                <SideCard v-if="accounts && (accounts.length || !showStart)" title="Gian hàng" :description="accounts.length ? `${accounts.length} gian hàng đã kết nối` : undefined">
                    <template #actions><RouterLink to="/marketplaces" class="rounded-[9px] border border-slate-200 px-3 py-1 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Quản lý</RouterLink></template>
                    <ul v-if="accounts.length" class="divide-y divide-slate-100 px-5">
                        <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 py-3">
                            <MarketplaceLogo :marketplace="a.marketplace" :name="a.marketplace_name" size="lg" />
                            <span class="min-w-0 flex-1 truncate text-[14px] font-medium text-slate-800">{{ a.shop_name }}</span>
                            <Badge :tone="accountTone(a)">{{ accountLabel(a) }}</Badge>
                        </li>
                    </ul>
                    <EmptyState v-else :icon="PlugZap" title="Chưa kết nối sàn" description="Kết nối Shopee, TikTok Shop, Lazada, Tiki để nhận đơn và đồng bộ tồn kho." />
                </SideCard>
            </aside>
        </div>
    </div>
</template>
