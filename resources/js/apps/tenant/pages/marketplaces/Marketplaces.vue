<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowRight, ArrowUpRight, BadgePercent, Boxes, ChevronDown, ChevronRight, CircleCheck, FlaskConical, KeyRound, Link2, Link2Off, MoreHorizontal, PackageX, PlugZap, Plus, Receipt, RefreshCw, Send, Settings2, ShieldCheck, ShoppingBag, ShoppingCart, Trash2, Undo2, Unplug, Wallet, Wrench, Zap, History } from 'lucide-vue-next';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import DonutChart from '@/shared/ui/charts/DonutChart.vue';
import { ago, btn, card, change, channelColor, daysLeft, dayLabel, iconTones, n, pill, rate, th, type ChannelStats, type ShopStats } from './channel';
import Badge from '@/shared/ui/Badge.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { capabilityLabels, capabilityNames, orderStatus } from '../orders/labels';
import TikiConnectDialog from './TikiConnectDialog.vue';
import WooConnectDialog from './WooConnectDialog.vue';
import { marketplaceLines } from '../../navigation';
import type { MarketplaceAccountData, MarketplaceCatalogEntry, OrderSummary, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
provide('soft-cards', true);
const toast = useToast();
const router = useRouter();
const route = useRoute();

/** One marketplace's own page from the sidebar (?san=shopee); without it, every channel. */
const san = computed(() => (typeof route.query.san === 'string' ? route.query.san : null));
const sanName = computed(() => marketplaceLines.find(([k]) => k === san.value)?.[1] ?? null);
const shownAccounts = computed(() => (san.value ? accounts.value.filter((a) => a.marketplace === san.value) : accounts.value));

const catalog = ref<MarketplaceCatalogEntry[]>([]);
const accounts = ref<MarketplaceAccountData[]>([]);
const webhooks = ref<{ id: number; marketplace: string; type: string; order: string | null; status: string; result: string | null; attempts: number; received_at: string; processed_at: string | null }[]>([]);
const warehouses = ref<Warehouse[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);

const connectOpen = ref(false);
const connectForm = ref({ shop_name: 'Shop giả lập', warehouse_id: '', setup: false });
const connectError = ref<ApiError | null>(null);

// Simulator (fake shops only)
const sim = ref<{ account: MarketplaceAccountData; failing: boolean; statuses: string[]; orders: OrderSummary[] } | null>(null);
const simForm = ref({ count: 3, status: 'fake_ready', max_items: 2, order: '', to: 'fake_packed' });

const realMarketplaces = computed(() => catalog.value.filter((c) => !c.is_fake && (!san.value || c.key === san.value)));
const connectNotice = ref<{ key: string; message: string } | null>(null);

const wooOpen = ref(false);
const tikiOpen = ref(false);

async function connectReal(c: MarketplaceCatalogEntry) {
    if (c.connect_mode === 'store') {
        wooOpen.value = true;
        return;
    }
    if (c.connect_mode === 'app_keys') {
        tikiOpen.value = true;
        return;
    }
    busy.value = `real-${c.key}`;
    connectNotice.value = null;
    try {
        const { data } = await api.post(`/marketplace-accounts/authorize/${c.key}`);
        window.location.href = data.data.url;
    } catch (e) {
        const err = e as ApiError;
        connectNotice.value = {
            key: c.key,
            message:
                err.body.code === 'CREDENTIALS_MISSING'
                    ? `Kết nối ${c.name} chưa được mở trên hệ thống. Liên hệ hỗ trợ để bật.`
                    : err.body.code === 'UNSUPPORTED_CAPABILITY'
                      ? `Kết nối ${c.name} sắp có.`
                      : err.message,
        };
    } finally {
        busy.value = null;
    }
}

const fakeAccounts = computed(() => accounts.value.filter((a) => a.marketplace === 'fake' && a.status === 'connected'));
const capabilityKeys = computed(() => Object.keys(catalog.value[0]?.capabilities ?? {}));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [c, a, w] = await Promise.all([api.get('/marketplaces'), api.get('/marketplace-accounts'), api.get('/marketplace-webhooks')]);
        catalog.value = c.data.data;
        accounts.value = a.data.data;
        webhooks.value = w.data.data;
        if (!sim.value && fakeAccounts.value[0]) await openSimulator(fakeAccounts.value[0]);
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    await load();
    warehouses.value = (await api.get('/warehouses')).data.data.filter((w: Warehouse) => w.status === 'active' && w.purpose !== 'defective');
    connectForm.value.warehouse_id = warehouses.value.find((w) => w.is_default)?.id ?? '';
});

async function connectFake() {
    busy.value = 'connect';
    connectError.value = null;
    try {
        const { data } = await api.post('/marketplace-accounts/fake', connectForm.value);
        toast.success('Đã kết nối shop giả lập và tải danh sách sản phẩm.');
        connectOpen.value = false;
        // A5: tried like a real shop, through the setup steps.
        if (data.data.setup_pending) return router.push(`/marketplaces/${data.data.id}/setup`);
        sim.value = null;
        await load();
    } catch (e) {
        connectError.value = e as ApiError;
    } finally {
        busy.value = null;
    }
}

async function run(key: string, fn: () => Promise<string | void>) {
    busy.value = key;
    try {
        const message = await fn();
        if (message) toast.success(message);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

const syncProducts = (a: MarketplaceAccountData) => run(`sync-${a.id}`, async () => {
    const { data } = await api.post(`/marketplace-accounts/${a.id}/sync-products`);
    await load();
    return `Đã tải ${data.data.variants} SKU từ ${data.data.listings} sản phẩm trên sàn (${data.data.suggested} gợi ý liên kết mới).`;
});
const pushStock = (a: MarketplaceAccountData) => run(`push-${a.id}`, async () => {
    const { data } = await api.post(`/marketplace-accounts/${a.id}/push-stock`);
    await load();
    return `Đã xếp hàng đợi đẩy tồn cho ${data.data.queued} SKU.`;
});
const toggleSync = (a: MarketplaceAccountData) => run(`toggle-${a.id}`, async () => {
    await api.patch(`/marketplace-accounts/${a.id}`, { sync_enabled: !a.sync_enabled });
    await load();
});
const changeWarehouse = (a: MarketplaceAccountData, warehouseId: string | undefined) => run(`wh-${a.id}`, async () => {
    await api.patch(`/marketplace-accounts/${a.id}`, { warehouse_id: warehouseId });
    await load();
    return 'Đã đổi kho xuất hàng cho gian hàng.';
});
const disconnect = (a: MarketplaceAccountData) => run(`disc-${a.id}`, async () => {
    await api.post(`/marketplace-accounts/${a.id}/disconnect`);
    await load();
    return 'Đã ngắt kết nối. Dữ liệu đơn cũ được giữ nguyên.';
});
/** "Xoá kênh": gone from the list. A channel with orders keeps them (reports unchanged); one without is deleted outright. */
const remove = (a: MarketplaceAccountData) => run(`remove-${a.id}`, async () => {
    if (!window.confirm(`Xoá kênh "${a.shop_name}" khỏi danh sách?\n\nKênh sẽ được ngắt kết nối, sản phẩm sàn và liên kết sản phẩm của kênh bị xoá. Đơn hàng cũ của kênh vẫn được giữ để báo cáo không đổi. Kết nối lại sau vẫn được.`)) return;
    const { data } = await api.delete(`/marketplace-accounts/${a.id}`);
    await load();
    return data.data.result === 'archived' ? `Đã xoá kênh ${a.shop_name}. Đơn hàng cũ của kênh vẫn được giữ.` : `Đã xoá kênh ${a.shop_name}.`;
});

async function openSimulator(a: MarketplaceAccountData) {
    const [state, orders] = await Promise.all([api.get(`/fake-marketplace/${a.id}`), api.get('/orders', { params: { account: a.id, per_page: 20 } })]);
    sim.value = { account: a, failing: state.data.data.failing, statuses: state.data.data.statuses, orders: orders.data.data };
    simForm.value.order = sim.value.orders[0]?.external_order_id ?? '';
}

async function refreshSim() {
    if (sim.value) await openSimulator(sim.value.account);
    webhooks.value = (await api.get('/marketplace-webhooks')).data.data;
}

const simCreate = () => run('sim-create', async () => {
    const { data } = await api.post(`/fake-marketplace/${sim.value!.account.id}/orders`, { count: simForm.value.count, status: simForm.value.status, max_items: simForm.value.max_items });
    await refreshSim();
    return `Sàn giả lập đã tạo ${data.data.orders.length} đơn và gửi webhook.`;
});
const simStatus = () => run('sim-status', async () => {
    await api.post(`/fake-marketplace/${sim.value!.account.id}/orders/${simForm.value.order}/status`, { status: simForm.value.to });
    await refreshSim();
    return `Đã đổi trạng thái đơn ${simForm.value.order} trên sàn giả lập.`;
});
const simReplay = () => run('sim-replay', async () => {
    const { data } = await api.post(`/fake-marketplace/${sim.value!.account.id}/orders/${simForm.value.order}/replay`);
    await refreshSim();
    return !data.data.duplicate ? 'Đã gửi lại webhook.' : 'Webhook trùng: hệ thống nhận diện và bỏ qua (không xử lý tồn lần hai).';
});
const simFailure = () => run('sim-fail', async () => {
    const { data } = await api.post(`/fake-marketplace/${sim.value!.account.id}/failure`, { enabled: !sim.value!.failing });
    sim.value!.failing = data.data.failing;
    return data.data.failing ? 'API sàn giả lập sẽ trả lỗi 503 (kiểm tra retry và circuit breaker).' : 'API sàn giả lập hoạt động lại.';
});

/** Products on the shop that are not linked to a warehouse SKU yet (their stock is not deducted automatically). */
const unlinked = (a: MarketplaceAccountData) => Math.max(0, (a.stats?.listings ?? 0) - (a.stats?.mapped ?? 0));
const linkedPct = (a: MarketplaceAccountData) => (a.stats?.listings ? Math.round((a.stats.mapped / a.stats.listings) * 100) : 0);
type Health = 'off' | 'error' | 'todo' | 'ok';
const health = (a: MarketplaceAccountData): Health => (a.status !== 'connected' ? 'off' : a.circuit_open || a.last_error ? 'error' : a.setup_pending || unlinked(a) ? 'todo' : 'ok');
const healthLabel: Record<Health, string> = { off: 'Đã ngắt', error: 'Mất kết nối', todo: 'Cần làm thêm', ok: 'Đang chạy' };
const healthClass: Record<Health, string> = { off: 'text-slate-500', error: 'text-rose-600', todo: 'text-amber-600', ok: 'text-emerald-600' };
const healthDot: Record<Health, string> = { off: 'bg-slate-400', error: 'bg-rose-500', todo: 'bg-amber-500', ok: 'bg-emerald-500' };

/** What each marketplace syncs, in one line for the "Thêm kênh bán" cards. */
const syncs = (c: MarketplaceCatalogEntry) => {
    if (c.key === 'woocommerce') return 'Đơn, tồn kho, giá hai chiều';
    return c.capabilities['inventory.update'] === 'supported' ? 'Đơn, tồn kho, giá' : 'Đơn';
};
const ready = (c: MarketplaceCatalogEntry) => c.connect_mode === 'store' || c.connect_mode === 'app_keys' || (c.available && c.credentials_configured && c.capabilities['shop.authorize'] === 'supported');

const menuFor = ref<string | null>(null);
const shownWebhooks = computed(() => (san.value ? webhooks.value.filter((w) => w.marketplace === san.value) : webhooks.value));

function reconnect(a: MarketplaceAccountData) {
    if (a.marketplace === 'woocommerce') return router.push(`/marketplaces/${a.id}/store`);
    const c = catalog.value.find((x) => x.key === a.marketplace);
    if (c) connectReal(c);
}
function sinceLabel(a: MarketplaceAccountData) {
    if (a.last_synced_at) return `Đồng bộ lần cuối ${formatDateTime(a.last_synced_at)}`;
    return a.connected_at ? `Kết nối ${formatDateTime(a.connected_at)}` : '';
}

// ---- Kênh bán (mẫu 28/11): sales per shop, chart, best sellers and what needs attention ------------------------------
const stats = ref<ChannelStats | null>(null);
async function loadStats() {
    try {
        stats.value = (await api.get('/marketplace-stats', { params: { marketplace: san.value ?? undefined, days: 30 } })).data.data;
    } catch {
        stats.value = null;
    }
}
onMounted(loadStats);
watch(san, loadStats);

const statOf = (a: MarketplaceAccountData): ShopStats | undefined => stats.value?.accounts.find((s) => s.id === a.id);
const shopStats = computed(() => (stats.value?.accounts ?? []).filter((s) => s.marketplace !== 'fake' || !san.value));
const sum = (f: (s: ShopStats) => number) => shopStats.value.reduce((t, s) => t + f(s), 0);
const totals = computed(() => ({
    revenue: sum((s) => Number(s.revenue)),
    prevRevenue: sum((s) => Number(s.previous.revenue)),
    today: sum((s) => s.orders_today),
    orders: sum((s) => s.orders),
    kept: sum((s) => s.kept),
    lost: sum((s) => s.cancelled + s.returned),
    cancelled: sum((s) => s.cancelled),
    returned: sum((s) => s.returned),
    listings: sum((s) => s.listings.total),
    mapped: sum((s) => s.listings.mapped),
    unlinked: sum((s) => s.listings.unmapped + s.listings.suggested),
    fees: sum((s) => Number(s.fees ?? 0)),
    errors: sum((s) => s.open_errors),
}));
const revenueChange = computed(() => change(totals.value.revenue, totals.value.prevRevenue));
const todayNote = computed(() => {
    const by = new Map<string, number>();
    for (const s of shopStats.value) by.set(s.marketplace, (by.get(s.marketplace) ?? 0) + s.orders_today);
    return [...by].map(([k, v]) => `${marketplaceLines.find(([m]) => m === k)?.[1] ?? k} ${v}`).join(' · ');
});
const openOrders = computed(() => stats.value?.open ?? {});
const toPack = computed(() => (openOrders.value.READY_TO_PACK ?? 0) + (openOrders.value.PENDING_CONFIRMATION ?? 0));

const chartBy = ref<'orders' | 'revenue'>('orders');
const chart = computed(() => {
    const s = stats.value;
    if (!s) return { labels: [] as string[], series: [] as { key: string; label: string; color: string; values: number[] }[] };
    const pick = (d: ShopStats['daily'][number]) => (chartBy.value === 'orders' ? d.orders : Number(d.revenue));
    // All channels: one colour per marketplace. One marketplace: one colour per shop (shades of its colour).
    const groups = new Map<string, { label: string; color: string; values: number[] }>();
    shopStats.value.forEach((a, i) => {
        const key = san.value ? a.id : a.marketplace;
        const label = san.value ? a.shop_name : (marketplaceLines.find(([m]) => m === a.marketplace)?.[1] ?? a.marketplace);
        const g = groups.get(key) ?? { label, color: san.value ? ['#f97316', '#fb923c', '#fdba74', '#0f172a', '#8b5cf6'][i % 5]! : channelColor(a.marketplace), values: s.dates.map(() => 0) };
        a.daily.forEach((d, j) => (g.values[j]! += pick(d)));
        groups.set(key, g);
    });
    return { labels: s.dates.map(dayLabel), series: [...groups].map(([key, g]) => ({ key, ...g })) };
});
const peak = computed(() => {
    const s = stats.value;
    if (!s?.dates.length) return null;
    const per = s.dates.map((_, j) => shopStats.value.reduce((t, a) => t + (a.daily[j]?.orders ?? 0), 0));
    const max = Math.max(...per);
    return max ? { day: dayLabel(s.dates[per.indexOf(max)]!), n: max } : null;
});
const share = computed(() => {
    const by = new Map<string, number>();
    for (const s of shopStats.value) by.set(s.marketplace, (by.get(s.marketplace) ?? 0) + Number(s.revenue));
    return [...by].filter(([, v]) => v > 0).map(([k, v]) => ({ key: k, label: marketplaceLines.find(([m]) => m === k)?.[1] ?? k, color: channelColor(k), value: v, to: `/marketplaces?san=${k}` }));
});

/** "Cần xử lý": one line per thing the shop should do, most urgent first. */
const todo = computed(() => {
    const out: { icon: typeof Link2Off; tone: string; text: string; note: string; action: string; to: string; key: string }[] = [];
    for (const a of shownAccounts.value) {
        if (a.status !== 'connected' || a.marketplace === 'fake') continue;
        if (a.circuit_open || a.last_error) out.push({ key: `err-${a.id}`, icon: AlertTriangle, tone: 'red', text: `${a.shop_name} đang lỗi đồng bộ`, note: a.last_error ?? 'Sàn báo lỗi nhiều lần nên đồng bộ tạm dừng', action: 'Xem', to: shopLink(a) });
        const s = statOf(a);
        const left = daysLeft(s?.token_expires_at);
        if (left !== null && left <= 14) out.push({ key: `tok-${a.id}`, icon: KeyRound, tone: 'amber', text: `${a.shop_name} hết hạn ủy quyền ${left <= 0 ? 'hôm nay' : `sau ${left} ngày`}`, note: 'Kết nối lại để không mất đơn', action: 'Kết nối lại', to: shopLink(a) });
        if (a.webhook_health && a.webhook_health.ok === false) out.push({ key: `hook-${a.id}`, icon: Zap, tone: 'amber', text: `${a.shop_name} nhận đơn chậm`, note: 'Webhook lỗi, đơn vẫn về qua đối soát định kỳ', action: 'Kiểm tra', to: shopLink(a) });
    }
    if (totals.value.unlinked) out.push({ key: 'unlinked', icon: Link2Off, tone: 'amber', text: `${n(totals.value.unlinked)} mã trên sàn chưa liên kết`, note: 'Đơn có mã này chưa tự trừ kho', action: 'Liên kết', to: firstUnlinked.value });
    if (stats.value?.pending_prices) out.push({ key: 'prices', icon: BadgePercent, tone: 'blue', text: `${n(stats.value.pending_prices)} giá mới chờ duyệt`, note: 'Giá gốc đổi, giá trên sàn chưa cập nhật', action: 'Duyệt giá', to: '/marketplaces/prices' });
    if (totals.value.errors) out.push({ key: 'errors', icon: PackageX, tone: 'red', text: `${n(totals.value.errors)} lỗi đồng bộ đang mở`, note: 'Đơn hoặc tồn chưa đồng bộ được', action: 'Xem lỗi', to: '/marketplaces/sync?tab=problems' });
    return out;
});
const firstUnlinked = computed(() => {
    const s = shopStats.value.find((x) => x.listings.unmapped + x.listings.suggested > 0);
    return s ? `/marketplaces/${s.id}/listings` : '/marketplaces';
});
const shopLink = (a: { id: string; marketplace: string }) => (a.marketplace === 'woocommerce' ? `/marketplaces/${a.id}/store` : a.marketplace === 'fake' ? `/marketplaces/${a.id}/listings` : `/marketplaces/${a.id}/shop`);
const openList = computed(() => [
    { key: 'PENDING_CONFIRMATION', label: 'Chờ xác nhận', dot: 'bg-slate-400' },
    { key: 'READY_TO_PACK', label: 'Chờ đóng gói', dot: 'bg-amber-500' },
    { key: 'PACKED', label: 'Chờ lấy hàng', dot: 'bg-blue-500' },
    { key: 'IN_TRANSIT', label: 'Đang giao', dot: 'bg-sky-500', also: 'HANDED_OVER' },
    { key: 'FAILED_DELIVERY', label: 'Giao thất bại', dot: 'bg-red-500' },
].map((r) => ({ ...r, n: (openOrders.value[r.key] ?? 0) + (r.also ? (openOrders.value[r.also] ?? 0) : 0) })));
const recentHooks = computed(() => shownWebhooks.value.slice(0, 6));
const topMax = computed(() => Math.max(1, ...(stats.value?.top ?? []).map((t) => t.quantity)));
const sanAdd = computed(() => realMarketplaces.value);
const revenueLabel = (v: number) => (v >= 1_000_000 ? `${(Math.round(v / 100_000) / 10).toLocaleString('vi-VN')} tr` : formatNumber(v));
</script>

<template>
    <ChannelHead
        :title="sanName ?? 'Tất cả kênh bán'"
        :marketplace="san"
        :description="san ? `${shownAccounts.length ? `${shownAccounts.length} gian hàng đã kết nối · ` : ''}đơn về ngay khi khách đặt, tồn tự đẩy lên sàn.` : 'Đơn, tồn kho và giá tự đồng bộ giữa kho của bạn và từng gian hàng.'"
    >
        <RouterLink to="/marketplaces/prices" :class="btn.outline" data-testid="price-board-link"><BadgePercent class="size-4" /> {{ sanName ? `Bảng giá ${sanName}` : 'Bảng giá sàn' }}</RouterLink>
        <RouterLink v-if="tenant.can('sync.view')" to="/marketplaces/sync" :class="btn.outline" data-testid="sync-log-link"><History class="size-4" /> {{ san ? 'Nhật ký đồng bộ' : 'Trung tâm đồng bộ' }}</RouterLink>
        <button v-if="tenant.can('marketplace.connect') && catalog.some((c) => c.is_fake)" type="button" :class="btn.outline" data-testid="connect-fake" @click="connectOpen = true"><FlaskConical class="size-4" /> Shop giả lập</button>
        <RouterLink v-if="san" :to="{ path: '/orders', query: { marketplace: san } }" :class="btn.primary" data-testid="channel-orders"><ShoppingCart class="size-4" /> Đơn {{ sanName }}</RouterLink>
        <a v-else-if="tenant.can('marketplace.connect')" href="#add-channel" :class="btn.primary" data-testid="channel-add"><Plus class="size-4" /> Kết nối kênh bán</a>
        <button type="button" :class="cn(btn.outline, 'w-11 justify-center px-0')" :disabled="loading" aria-label="Làm mới" title="Làm mới" @click="load(); loadStats()"><RefreshCw :class="cn('size-4', loading && 'animate-spin')" /></button>
    </ChannelHead>

    <div v-if="loading && !catalog.length" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else class="space-y-5">
        <div v-if="stats && shopStats.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="channel-kpis">
            <ReportKpi primary :label="sanName ? `Doanh thu ${sanName} 30 ngày` : 'Doanh thu kênh bán 30 ngày'" :icon="Wallet" :value="totals.revenue" tip="Tiền hàng các đơn đặt trong 30 ngày, trừ đơn huỷ, hoàn, chưa thanh toán.">
                <template v-if="revenueChange" #chips><ReportChip tone="onblue">{{ revenueChange.text }}</ReportChip></template>
                {{ n(totals.kept) }} đơn không huỷ, hoàn<template v-if="totals.kept"> · giá trị TB {{ formatNumber(Math.round(totals.revenue / totals.kept)) }}đ</template>
            </ReportKpi>
            <ReportKpi label="Đơn hôm nay" :icon="ShoppingBag" tone="green" plain :value="totals.today" unit="">
                <template #chips>
                    <ReportChip v-if="toPack" tone="amber">{{ n(toPack) }} chờ đóng gói</ReportChip>
                    <ReportChip v-if="openOrders.PACKED" tone="blue">{{ n(openOrders.PACKED) }} chờ lấy hàng</ReportChip>
                </template>
                {{ san ? `Cập nhật ngay khi ${sanName} báo đơn` : todayNote || 'Chưa có đơn hôm nay' }}
            </ReportKpi>
            <ReportKpi v-if="san && stats.can_finance" label="Phí sàn đã trừ" :icon="Receipt" tone="violet" plain :value="totals.fees" tip="Phí cố định, phí thanh toán, phí dịch vụ của các đơn trong 30 ngày, theo số sàn quyết toán.">
                {{ totals.revenue ? `${rate(totals.fees, totals.revenue)} doanh thu` : 'Chưa có đơn quyết toán' }}
            </ReportKpi>
            <ReportKpi v-else label="Sản phẩm đã liên kết" :icon="Link2" tone="violet" plain :value="null" :text="rate(totals.mapped, totals.listings)" unit="">
                <template v-if="totals.unlinked" #chips><ReportChip tone="amber">{{ n(totals.unlinked) }} mã chưa liên kết</ReportChip></template>
                {{ n(totals.mapped) }} / {{ n(totals.listings) }} mã trên sàn nối với kho
            </ReportKpi>
            <ReportKpi v-if="san" label="Sản phẩm đã liên kết" :icon="Boxes" tone="green" plain :value="null" :text="rate(totals.mapped, totals.listings)" unit="">
                <template #chips><ReportChip>{{ n(totals.listings) }} mã đang bán</ReportChip></template>
                {{ totals.unlinked ? `${n(totals.unlinked)} mã chờ liên kết` : 'Mọi mã đã nối với kho' }}
            </ReportKpi>
            <ReportKpi v-else label="Tỉ lệ huỷ và hoàn" :icon="Undo2" tone="red" plain :value="null" :text="rate(totals.lost, totals.orders)" unit="">
                {{ n(totals.cancelled) }} đơn huỷ, {{ n(totals.returned) }} đơn hoàn trong 30 ngày
            </ReportKpi>
        </div>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_400px] 2xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="min-w-0 space-y-5">
                <!-- Shops: a table-like list on "Tất cả kênh", one card per shop on a marketplace's page. -->
                <section :class="cn(card, 'overflow-visible')">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">{{ san ? `Gian hàng ${sanName}` : 'Các gian hàng đang bán' }}</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">Số liệu 30 ngày gần nhất · bấm tên để mở trang gian hàng</p>
                        </div>
                        <a v-if="tenant.can('marketplace.connect')" href="#add-channel" :class="cn(btn.small, 'border-blue-100 bg-blue-50 text-blue-700')"><Plus class="size-3.5" /> Kết nối gian hàng</a>
                    </header>
                    <div v-if="!shownAccounts.length">
                        <EmptyState :icon="PlugZap" :title="sanName ? `Chưa kết nối ${sanName}` : 'Chưa kết nối gian hàng nào'" description="Kết nối gian hàng ở mục Thêm kênh bán để đơn, tồn kho và giá tự đồng bộ." />
                    </div>
                    <ul v-else class="divide-y divide-slate-100" data-testid="account-list">
                        <li v-for="a in shownAccounts" :key="a.id" class="last:rounded-b-[14px]">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4">
                                <div class="flex min-w-[240px] flex-1 items-center gap-3">
                                    <MarketplaceLogo :marketplace="a.marketplace" :name="a.marketplace_name" size="lg" class="!size-11 !rounded-[12px]" />
                                    <div class="min-w-0">
                                        <RouterLink :to="shopLink(a)" class="block truncate text-[15px] font-semibold text-slate-900 hover:text-blue-600">{{ a.shop_name }} <Badge v-if="a.marketplace === 'fake'" tone="warning" class="ml-1 align-middle">Giả lập</Badge></RouterLink>
                                        <p class="flex flex-wrap items-center gap-x-1.5 text-[13px] text-slate-500">
                                            <span :class="['inline-flex items-center gap-1 font-medium', healthClass[health(a)]]"><i :class="['size-1.5 rounded-full', healthDot[health(a)]]" />{{ healthLabel[health(a)] }}</span>
                                            <span>· {{ a.marketplace_name }}<template v-if="a.warehouse"> · {{ a.warehouse.name }}</template></span>
                                        </p>
                                        <p class="text-[12px] text-slate-400">{{ sinceLabel(a) }}</p>
                                    </div>
                                </div>
                                <div v-if="statOf(a)" class="grid grid-cols-4 gap-5 text-right" :data-testid="`shop-stats-${a.id}`">
                                    <div><p class="text-[12px] text-slate-400">Hôm nay</p><p class="text-[15px] font-semibold tabular-nums">{{ n(statOf(a)!.orders_today) }}</p></div>
                                    <div><p class="text-[12px] text-slate-400">30 ngày</p><p class="text-[15px] tabular-nums">{{ n(statOf(a)!.orders) }} đơn</p></div>
                                    <div><p class="text-[12px] text-slate-400">Doanh thu</p><p class="text-[15px] font-semibold tabular-nums">{{ revenueLabel(Number(statOf(a)!.revenue)) }}</p></div>
                                    <div><p class="text-[12px] text-slate-400">Huỷ, hoàn</p><p class="text-[15px] tabular-nums">{{ rate(statOf(a)!.cancelled + statOf(a)!.returned, statOf(a)!.orders) }}</p></div>
                                </div>
                                <div v-if="a.stats" class="w-40">
                                    <div class="flex justify-between text-[12px]"><span class="text-slate-400">Liên kết SP</span><span class="font-medium tabular-nums text-slate-700">{{ formatNumber(a.stats.mapped) }}/{{ formatNumber(a.stats.listings) }}</span></div>
                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <i :class="['block h-full rounded-full', linkedPct(a) >= 100 ? 'bg-emerald-500' : 'bg-violet-500']" :style="{ width: `${Math.max(linkedPct(a), a.stats.listings ? 2 : 0)}%` }" />
                                    </div>
                                    <p v-if="a.stats.push_errors" class="mt-1 text-[12px] text-rose-600">{{ formatNumber(a.stats.push_errors) }} SP đẩy tồn lỗi</p>
                                </div>
                                <div v-if="a.status === 'connected'" class="w-24">
                                    <p class="text-[12px] text-slate-400">Đẩy tồn</p>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="a.sync_enabled"
                                        aria-label="Tự cập nhật tồn kho lên sàn"
                                        :disabled="!tenant.can('marketplace.connect') || busy === `toggle-${a.id}`"
                                        :class="['relative mt-1 h-6 w-11 rounded-full transition-colors disabled:opacity-60', a.sync_enabled ? 'bg-blue-600' : 'bg-slate-200']"
                                        :data-testid="`sync-toggle-${a.id}`"
                                        @click="toggleSync(a)"
                                    >
                                        <span :class="['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', a.sync_enabled ? 'left-[22px]' : 'left-0.5']" />
                                    </button>
                                </div>
                                <div v-if="a.status === 'connected'" class="relative ml-auto flex flex-wrap items-center gap-2">
                                    <RouterLink v-if="a.marketplace === 'woocommerce'" :to="`/marketplaces/${a.id}/store`" :class="btn.small" :data-testid="`woo-settings-${a.id}`"><Settings2 class="size-3.5" /> Cài đặt đồng bộ</RouterLink>
                                    <RouterLink v-else-if="a.marketplace !== 'fake'" :to="`/marketplaces/${a.id}/shop`" :class="btn.small" :data-testid="`shop-settings-${a.id}`"><Settings2 class="size-3.5" /> Cài đặt đồng bộ</RouterLink>
                                    <RouterLink :to="`/marketplaces/${a.id}/listings`" :class="btn.small"><Link2 class="size-3.5" /> Liên kết sản phẩm</RouterLink>
                                    <button type="button" :class="cn(btn.small, 'w-9 justify-center px-0')" aria-label="Thêm thao tác" :data-testid="`account-menu-${a.id}`" @click="menuFor = menuFor === a.id ? null : a.id"><MoreHorizontal class="size-4" /></button>
                                    <template v-if="menuFor === a.id">
                                        <div class="fixed inset-0 z-30" @click="menuFor = null" />
                                        <div class="absolute right-0 top-full z-40 mt-1 w-64 rounded-[12px] border border-slate-200 bg-white p-1.5 text-sm shadow-lg" role="menu">
                                            <div v-if="tenant.can('marketplace.connect')" class="px-2 pb-2 pt-1">
                                                <p class="mb-1 text-xs text-muted-foreground">Kho xuất hàng</p>
                                                <Select :model-value="a.warehouse?.id ?? ''" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" aria-label="Kho xuất" @update:model-value="(v) => changeWarehouse(a, v)" />
                                            </div>
                                            <template v-if="tenant.can('marketplace.connect')">
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left hover:bg-muted" :disabled="busy === `sync-${a.id}`" @click="menuFor = null; syncProducts(a)"><RefreshCw class="size-4 text-muted-foreground" /> Tải lại sản phẩm từ sàn</button>
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left hover:bg-muted" :disabled="busy === `push-${a.id}`" @click="menuFor = null; pushStock(a)"><Send class="size-4 text-muted-foreground" /> Đẩy tồn kho ngay</button>
                                                <div class="my-1 border-t" />
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-rose-600 hover:bg-rose-50" @click="menuFor = null; disconnect(a)"><Unplug class="size-4" /> Ngắt kết nối</button>
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-rose-600 hover:bg-rose-50" :data-testid="`account-remove-${a.id}`" @click="menuFor = null; remove(a)"><Trash2 class="size-4" /> Xoá kênh</button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div v-if="a.setup_pending && a.status === 'connected'" class="flex flex-wrap items-center gap-3 border-t border-blue-100 bg-blue-50/60 px-5 py-3 text-sm">
                                <Wrench class="size-4 text-blue-600" />
                                <span class="flex-1">Chưa thiết lập xong: ghép sản phẩm, nhập tồn ban đầu rồi bật đồng bộ.</span>
                                <RouterLink v-if="tenant.can('marketplace.connect')" :to="`/marketplaces/${a.id}/setup`" :class="btn.smallPrimary" :data-testid="`setup-${a.id}`">Thiết lập shop</RouterLink>
                            </div>
                            <div v-else-if="health(a) === 'error'" class="flex flex-wrap items-center gap-3 border-t border-rose-100 bg-rose-50/70 px-5 py-3 text-sm">
                                <AlertTriangle class="size-4 shrink-0 text-rose-600" />
                                <div class="min-w-0 flex-1">
                                    <p>{{ a.circuit_open ? `${a.marketplace_name} báo lỗi nhiều lần nên đồng bộ đang tạm dừng.` : `Không đồng bộ được với ${a.marketplace_name}.` }} Đơn mới có thể chưa về.</p>
                                    <p v-if="a.last_error" class="mt-0.5 truncate text-xs text-muted-foreground" :title="a.last_error">Chi tiết: {{ a.last_error }}</p>
                                </div>
                                <Button v-if="tenant.can('marketplace.connect') && a.marketplace !== 'fake'" size="sm" variant="destructive" @click="reconnect(a)">Kết nối lại</Button>
                            </div>
                            <div v-else-if="a.status === 'connected' && unlinked(a)" class="flex flex-wrap items-center gap-3 border-t border-amber-100 bg-amber-50/60 px-5 py-2.5 text-sm">
                                <Link2Off class="size-4 shrink-0 text-amber-600" />
                                <span class="flex-1">{{ formatNumber(unlinked(a)) }} sản phẩm chưa liên kết với kho nên chưa tự trừ tồn.<template v-if="a.stats?.suggested"> Có {{ formatNumber(a.stats.suggested) }} gợi ý ghép sẵn.</template></span>
                                <RouterLink :to="`/marketplaces/${a.id}/listings`" class="inline-flex items-center gap-1 text-[13px] font-medium text-amber-700 hover:text-amber-800">Liên kết {{ formatNumber(unlinked(a)) }} sản phẩm <ArrowRight class="size-3.5" /></RouterLink>
                            </div>
                            <div v-else-if="a.status !== 'connected'" class="flex flex-wrap items-center gap-3 border-t border-slate-100 bg-slate-50 px-5 py-3 text-sm text-muted-foreground">
                                <Unplug class="size-4 shrink-0" />
                                <span class="flex-1">Đã ngắt kết nối. Đơn cũ vẫn được giữ.</span>
                                <Button v-if="tenant.can('marketplace.connect')" size="sm" variant="ghost" class="text-rose-600 hover:bg-rose-50 hover:text-rose-700" :data-testid="`account-remove-${a.id}`" @click="remove(a)"><Trash2 /> Xoá kênh</Button>
                                <Button v-if="tenant.can('marketplace.connect') && a.marketplace !== 'fake'" size="sm" variant="outline" @click="reconnect(a)">Kết nối lại</Button>
                            </div>
                        </li>
                    </ul>
                </section>

                <section v-if="stats && chart.series.length" :class="card" data-testid="channel-chart">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">{{ san ? `Đơn ${sanName} theo ngày` : 'Đơn theo ngày, từng kênh' }}</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">{{ n(totals.orders) }} đơn trong 30 ngày<template v-if="peak"> · ngày {{ peak.day }} nhiều nhất ({{ n(peak.n) }} đơn)</template></p>
                        </div>
                        <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Xem theo">
                            <button type="button" :class="pill(chartBy === 'orders')" @click="chartBy = 'orders'">Đơn</button>
                            <button type="button" :class="pill(chartBy === 'revenue')" @click="chartBy = 'revenue'">Doanh thu</button>
                        </div>
                    </header>
                    <div class="px-5 py-4"><StackedBarChart :labels="chart.labels" :series="chart.series" :height="240" /></div>
                </section>

                <section v-if="san && stats?.top.length" :class="cn(card, 'overflow-hidden')" data-testid="channel-top">
                    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div><h3 class="text-[16px] font-semibold text-slate-900">Bán chạy trên {{ sanName }}</h3><p class="mt-0.5 text-[13px] text-slate-500">30 ngày gần nhất, không tính đơn huỷ, hoàn</p></div>
                        <RouterLink v-if="tenant.can('reports.view')" to="/reports/products" class="flex items-center gap-1 text-[13px] font-medium text-blue-600">Xem báo cáo <ArrowRight class="size-3.5" /></RouterLink>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px]">
                            <thead class="bg-slate-50/70"><tr><th :class="cn(th, 'pl-5 text-left')">Sản phẩm</th><th :class="cn(th, 'text-right')">Đã bán</th><th :class="cn(th, 'text-right')">Doanh thu</th><th :class="th" /></tr></thead>
                            <tbody>
                                <tr v-for="t in stats.top" :key="`${t.name}-${t.variant_name}`" class="border-t border-slate-100">
                                    <td class="px-5 py-3"><p class="text-[14px] font-medium text-slate-900">{{ t.name }}</p><p class="text-[12px] text-slate-500">{{ [t.variant_name, t.sku].filter(Boolean).join(' · ') }}</p></td>
                                    <td class="px-4 py-3 text-right text-[14px] tabular-nums">{{ n(t.quantity) }}</td>
                                    <td class="px-4 py-3 text-right text-[14px] font-semibold tabular-nums">{{ revenueLabel(Number(t.revenue)) }}</td>
                                    <td class="w-[180px] px-5 py-3"><div class="h-1.5 overflow-hidden rounded-full bg-slate-100"><i class="block h-full rounded-full" :style="{ width: `${(t.quantity / topMax) * 100}%`, background: channelColor(san ?? '') }" /></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="min-w-0 space-y-5">
                <section v-if="san" :class="card" data-testid="channel-open">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Đơn {{ sanName }} đang xử lý</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Bấm để mở Đơn hàng đã lọc theo {{ sanName }}</p>
                    </header>
                    <RouterLink v-for="r in openList" :key="r.key" :to="{ path: '/orders', query: { marketplace: san, status: r.key } }" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 first-of-type:border-t-0 hover:bg-slate-50">
                        <span class="flex items-center gap-2.5 text-[14px] text-slate-700"><i :class="['size-2 rounded-full', r.dot]" />{{ r.label }}</span>
                        <span class="flex items-center gap-2 text-[15px] font-semibold tabular-nums">{{ n(r.n) }} <ChevronRight class="size-4 text-slate-300" /></span>
                    </RouterLink>
                </section>

                <section v-else :class="card" data-testid="channel-todo">
                    <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Cần xử lý</h3>
                        <span v-if="todo.length" class="rounded-md bg-red-50 px-2 py-0.5 text-[12px] font-medium text-red-600">{{ todo.length }} việc</span>
                    </header>
                    <RouterLink v-for="t in todo" :key="t.key" :to="t.to" class="flex items-center gap-3 border-t border-slate-100 px-5 py-3 first-of-type:border-t-0 hover:bg-slate-50">
                        <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', iconTones[t.tone])"><component :is="t.icon" class="size-[18px]" :stroke-width="1.9" /></span>
                        <span class="min-w-0 flex-1"><span class="block text-[14px] font-medium text-slate-900">{{ t.text }}</span><span class="block truncate text-[12px] text-slate-500">{{ t.note }}</span></span>
                        <span class="flex shrink-0 items-center gap-1 text-[13px] font-medium text-blue-600">{{ t.action }} <ArrowRight class="size-3.5" /></span>
                    </RouterLink>
                    <div v-if="!todo.length" class="flex items-center gap-3 px-5 py-6 text-[14px] text-slate-500"><CircleCheck class="size-5 text-emerald-500" /> Không có việc gấp, mọi kênh đang đồng bộ tốt.</div>
                </section>

                <section v-if="!san && share.length > 1" :class="card" data-testid="channel-share">
                    <header class="border-b border-slate-100 px-5 py-4"><h3 class="text-[16px] font-semibold text-slate-900">Tỉ trọng doanh thu</h3><p class="mt-0.5 text-[13px] text-slate-500">30 ngày gần nhất</p></header>
                    <div class="p-5"><DonutChart :items="share" /></div>
                </section>

                <section v-if="san && recentHooks.length" :class="card" data-testid="channel-recent">
                    <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Tin báo gần đây từ sàn</h3>
                        <RouterLink v-if="tenant.can('sync.view')" to="/marketplaces/sync" class="flex items-center gap-1 text-[13px] font-medium text-blue-600">Nhật ký <ArrowRight class="size-3.5" /></RouterLink>
                    </header>
                    <div class="py-1.5">
                        <div v-for="w in recentHooks" :key="w.id" class="flex items-center gap-3 px-5 py-2.5">
                            <span :class="cn('grid size-7 shrink-0 place-items-center rounded-full', w.status === 'failed' ? iconTones.red : w.status === 'processed' ? iconTones.green : iconTones.amber)">
                                <component :is="w.status === 'failed' ? AlertTriangle : w.status === 'processed' ? CircleCheck : RefreshCw" class="size-3.5" />
                            </span>
                            <p class="min-w-0 flex-1 truncate text-[14px] text-slate-700">{{ w.order ? `Đơn ${w.order}` : w.type }}<span v-if="w.result" class="text-slate-400"> · {{ w.result }}</span></p>
                            <span class="whitespace-nowrap text-[12px] text-slate-400">{{ ago(w.received_at) }}</span>
                        </div>
                    </div>
                </section>

                <section id="add-channel" :class="cn(card, san && 'border-dashed')">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">{{ sanName ? `Thêm gian hàng ${sanName}` : 'Thêm kênh bán' }}</h3>
                        <p class="mt-0.5 flex items-center gap-1.5 text-[13px] text-slate-500"><ShieldCheck class="size-3.5 text-emerald-600" /> Đăng nhập qua trang chính thức của sàn, không cần mật khẩu.</p>
                    </header>
                    <div class="space-y-2 p-4" data-testid="real-marketplaces">
                        <div v-for="c in sanAdd" :key="c.key" :class="cn('rounded-[12px] border border-slate-200/80 px-3 py-2.5', !ready(c) && 'bg-slate-50/70')">
                            <div class="flex items-center gap-3">
                                <MarketplaceLogo :marketplace="c.key" :name="c.name" size="md" class="!size-8 !rounded-lg" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[14px] font-medium text-slate-900">{{ c.name }}</p>
                                    <p class="text-[12px] text-slate-500">{{ ready(c) ? syncs(c) : 'Sắp có' }}</p>
                                </div>
                                <button
                                    v-if="tenant.can('marketplace.connect')"
                                    type="button"
                                    :class="btn.small"
                                    :disabled="!c.available || busy === `real-${c.key}`"
                                    :data-testid="`connect-${c.key}`"
                                    @click="connectReal(c)"
                                >
                                    <Plus class="size-3.5" /> Kết nối
                                </button>
                            </div>
                            <p v-if="c.capabilities['inventory.update'] !== 'supported'" class="mt-1.5 text-[12px] text-slate-500" :data-testid="`stock-note-${c.key}`">
                                Tồn kho trên sàn: {{ c.capabilities['inventory.update'] === 'unsupported' ? 'sàn không cho cập nhật tự động' : 'chưa tự cập nhật được' }}, cập nhật tay trên Seller Center.
                            </p>
                            <p v-if="connectNotice?.key === c.key" class="mt-1.5 text-[12px] text-destructive" :data-testid="`connect-notice-${c.key}`">{{ connectNotice.message }}</p>
                        </div>
                    </div>
                </section>
            </aside>
        </div>

        <Card v-if="sim" title="Bảng điều khiển sàn giả lập" :description="`Đóng vai sàn cho shop “${sim.account.shop_name}” để chạy thử: tạo đơn, đổi trạng thái, giả lập sàn lỗi.`" data-testid="simulator">
            <template v-if="fakeAccounts.length > 1" #actions>
                <Select class="w-48" :model-value="sim.account.id" :options="fakeAccounts.map((a) => ({ value: a.id, label: a.shop_name }))" @update:model-value="(id) => openSimulator(fakeAccounts.find((a) => a.id === id)!)" />
            </template>
            <div class="grid gap-6 p-5 md:grid-cols-3">
                <div class="space-y-3">
                    <h4 class="text-sm font-medium">Tạo đơn mới trên sàn</h4>
                    <div class="grid grid-cols-2 gap-2">
                        <FormField label="Số đơn"><Input v-model.number="simForm.count" data-testid="sim-count" type="number" min="1" max="50" /></FormField>
                        <FormField label="Tối đa SP/đơn"><Input v-model.number="simForm.max_items" type="number" min="1" max="5" /></FormField>
                    </div>
                    <FormField label="Trạng thái ban đầu"><Select v-model="simForm.status" :options="sim.statuses.map((s) => ({ value: s, label: s }))" /></FormField>
                    <Button :loading="busy === 'sim-create'" data-testid="sim-create" @click="simCreate"><Zap /> Tạo đơn</Button>
                </div>
                <div class="space-y-3">
                    <h4 class="text-sm font-medium">Đổi trạng thái đơn trên sàn</h4>
                    <FormField label="Đơn">
                        <Select v-model="simForm.order" :options="sim.orders.map((o) => ({ value: o.external_order_id, label: `${o.external_order_id} · ${orderStatus[o.status]?.label ?? o.status}` }))" />
                    </FormField>
                    <FormField label="Trạng thái mới"><Select v-model="simForm.to" :options="sim.statuses.map((s) => ({ value: s, label: s }))" /></FormField>
                    <div class="flex gap-2">
                        <Button :disabled="!simForm.order" :loading="busy === 'sim-status'" @click="simStatus">Cập nhật</Button>
                        <Button variant="outline" :disabled="!simForm.order" :loading="busy === 'sim-replay'" @click="simReplay">Gửi lại webhook</Button>
                    </div>
                </div>
                <div class="space-y-3">
                    <h4 class="text-sm font-medium">Sự cố</h4>
                    <p class="text-xs text-muted-foreground">Bật để API sàn giả lập trả lỗi 503: hệ thống thử lại, lỗi liên tiếp sẽ tạm dừng đồng bộ.</p>
                    <Button :variant="sim.failing ? 'destructive' : 'outline'" :loading="busy === 'sim-fail'" data-testid="sim-failure" @click="simFailure">{{ sim.failing ? 'Đang giả lập lỗi — Tắt' : 'Giả lập sàn lỗi' }}</Button>
                </div>
            </div>
        </Card>

        <details :class="cn(card, 'group')" data-testid="tech-details">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-3.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                <Wrench class="size-4" /> Thông tin kỹ thuật (tin báo từ sàn, khả năng từng sàn)
                <ChevronDown class="ml-auto size-4 transition group-open:rotate-180" />
            </summary>
            <div class="space-y-5 border-t p-5">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h4 class="text-sm font-semibold">Tin báo gần đây từ sàn</h4>
                        <Button size="sm" variant="ghost" aria-label="Làm mới" @click="refreshSim"><RefreshCw /></Button>
                    </div>
                    <div v-if="!shownWebhooks.length" class="py-4 text-sm text-muted-foreground">Chưa nhận tin báo nào.</div>
                    <div v-else class="max-h-80 overflow-y-auto rounded-lg border">
                        <table class="w-full text-sm" data-testid="webhook-table">
                            <thead class="sticky top-0 border-b bg-slate-50 text-left text-xs text-muted-foreground">
                                <tr><th class="px-4 py-2 font-medium">Nhận lúc</th><th class="px-4 py-2 font-medium">Loại</th><th class="px-4 py-2 font-medium">Đơn</th><th class="px-4 py-2 font-medium">Trạng thái</th><th class="px-4 py-2 font-medium">Kết quả</th><th class="px-4 py-2 text-right font-medium">Lần thử</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="w in shownWebhooks" :key="w.id">
                                    <td class="px-4 py-2 text-xs text-muted-foreground">{{ formatDateTime(w.received_at) }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ w.type }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ w.order }}</td>
                                    <td class="px-4 py-2"><Badge :tone="w.status === 'processed' ? 'success' : w.status === 'failed' ? 'danger' : 'warning'">{{ w.status }}</Badge></td>
                                    <td class="px-4 py-2 text-xs">{{ w.result }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ w.attempts }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <h4 class="mb-2 text-sm font-semibold">Khả năng từng sàn</h4>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm" data-testid="capability-matrix">
                            <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">Thao tác</th>
                                    <th v-for="c in catalog" :key="c.key" class="px-4 py-2.5 text-center font-medium">{{ c.name }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="k in capabilityKeys" :key="k">
                                    <td class="px-4 py-2">{{ capabilityNames[k] ?? k }}</td>
                                    <td v-for="c in catalog" :key="c.key" class="px-4 py-2 text-center"><Badge :tone="capabilityLabels[c.capabilities[k] ?? 'unknown']?.tone">{{ capabilityLabels[c.capabilities[k] ?? 'unknown']?.label }}</Badge></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </details>
    </div>

    <WooConnectDialog v-model:open="wooOpen" :warehouses="warehouses" @connected="load" />
    <TikiConnectDialog v-model:open="tikiOpen" :warehouses="warehouses" @connected="load" />

    <Dialog v-model:open="connectOpen" title="Kết nối shop giả lập" description="Shop giả lập chỉ dùng để chạy thử, được tắt hoàn toàn trên production (FAKE_MARKETPLACE_ENABLED=false). Danh sách sản phẩm được tạo từ SKU của bạn.">
        <div class="space-y-4">
            <FormField label="Tên shop" :error="connectError?.field('shop_name')"><Input v-model="connectForm.shop_name" /></FormField>
            <FormField label="Kho xuất hàng"><Select v-model="connectForm.warehouse_id" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" /></FormField>
            <label class="flex items-start gap-2 text-sm"><input v-model="connectForm.setup" type="checkbox" class="mt-0.5" data-testid="connect-fake-setup" /> Đi qua các bước thiết lập như shop thật (ghép SKU, tồn ban đầu, bật đồng bộ)</label>
            <p v-if="connectError && !connectError.field('shop_name')" class="text-sm text-destructive">{{ connectError.message }}</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="connectOpen = false">Hủy</Button>
            <Button :loading="busy === 'connect'" data-testid="connect-fake-submit" @click="connectFake">Kết nối</Button>
        </template>
    </Dialog>
</template>
