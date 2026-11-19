<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, BadgePercent, ChevronDown, CircleCheck, FlaskConical, Link2, Link2Off, MoreHorizontal, PlugZap, Plus, RefreshCw, Send, Settings2, ShieldCheck, Unplug, Wrench, Zap, History } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
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
import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { capabilityLabels, capabilityNames, orderStatus } from '../orders/labels';
import TikiConnectDialog from './TikiConnectDialog.vue';
import WooConnectDialog from './WooConnectDialog.vue';
import { marketplaceLines } from '../../navigation';
import type { MarketplaceAccountData, MarketplaceCatalogEntry, OrderSummary, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
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
</script>

<template>
    <PageHeader :title="sanName ?? 'Kênh bán'" description="Đơn, tồn kho và giá tự đồng bộ với từng gian hàng.">
        <RouterLink to="/marketplaces/prices"><Button variant="outline" data-testid="price-board-link"><BadgePercent /> Bảng giá sàn</Button></RouterLink>
        <RouterLink v-if="tenant.can('sync.view')" to="/marketplaces/sync"><Button variant="outline" data-testid="sync-log-link"><History /> Nhật ký đồng bộ</Button></RouterLink>
        <Button v-if="tenant.can('marketplace.connect') && catalog.some((c) => c.is_fake)" variant="outline" data-testid="connect-fake" @click="connectOpen = true"><FlaskConical /> Shop giả lập</Button>
        <Button variant="outline" size="icon" :loading="loading" aria-label="Làm mới" title="Làm mới" @click="load"><RefreshCw /></Button>
    </PageHeader>

    <div v-if="loading && !catalog.length" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else class="space-y-8">
        <section>
            <div v-if="!shownAccounts.length" class="rounded-xl border bg-card shadow-xs">
                <EmptyState :icon="PlugZap" :title="sanName ? `Chưa kết nối ${sanName}` : 'Chưa kết nối gian hàng nào'" description="Kết nối gian hàng ở phần bên dưới để đơn, tồn kho và giá tự đồng bộ." />
            </div>
            <ul v-else class="space-y-3" data-testid="account-list">
                <li v-for="a in shownAccounts" :key="a.id" class="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-4 px-5 py-4">
                        <div class="flex min-w-60 flex-1 items-center gap-4">
                            <MarketplaceLogo :marketplace="a.marketplace" :name="a.marketplace_name" size="lg" class="size-11" />
                            <div class="min-w-0">
                                <p class="truncate text-base font-semibold">{{ a.shop_name }} <Badge v-if="a.marketplace === 'fake'" tone="warning" class="ml-1 align-middle">Giả lập</Badge></p>
                                <p class="text-sm text-muted-foreground">
                                    {{ a.marketplace_name }} ·
                                    <span :class="['inline-flex items-center gap-1 font-medium', healthClass[health(a)]]"><i :class="['size-1.5 rounded-full', healthDot[health(a)]]" />{{ healthLabel[health(a)] }}</span>
                                </p>
                                <p class="text-xs text-muted-foreground">{{ sinceLabel(a) }}<template v-if="a.warehouse"> · Xuất từ {{ a.warehouse.name }}</template></p>
                            </div>
                        </div>
                        <div v-if="a.stats" class="w-44">
                            <p class="text-xs text-muted-foreground">Sản phẩm đã liên kết</p>
                            <p class="text-base font-semibold tabular-nums">{{ formatNumber(a.stats.mapped) }} / {{ formatNumber(a.stats.listings) }}</p>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <i :class="['block h-full rounded-full', linkedPct(a) >= 100 ? 'bg-emerald-500' : linkedPct(a) >= 50 ? 'bg-amber-500' : 'bg-orange-500']" :style="{ width: `${Math.max(linkedPct(a), a.stats.listings ? 2 : 0)}%` }" />
                            </div>
                            <p v-if="a.stats.push_errors" class="mt-1 text-xs text-rose-600">{{ formatNumber(a.stats.push_errors) }} sản phẩm đẩy tồn bị lỗi</p>
                        </div>
                        <div v-if="a.status === 'connected'" class="w-32">
                            <p class="text-xs text-muted-foreground">Tự đẩy tồn lên sàn</p>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="a.sync_enabled"
                                aria-label="Tự cập nhật tồn kho lên sàn"
                                :disabled="!tenant.can('marketplace.connect') || busy === `toggle-${a.id}`"
                                :class="['relative mt-1.5 h-5 w-9 rounded-full transition-colors disabled:opacity-60', a.sync_enabled ? 'bg-primary' : 'bg-slate-300']"
                                :data-testid="`sync-toggle-${a.id}`"
                                @click="toggleSync(a)"
                            >
                                <span :class="['absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', a.sync_enabled ? 'left-[18px]' : 'left-0.5']" />
                            </button>
                        </div>
                        <div v-if="a.status === 'connected'" class="relative ml-auto flex items-center gap-2">
                            <RouterLink v-if="a.marketplace === 'woocommerce'" :to="`/marketplaces/${a.id}/store`"><Button variant="outline" :data-testid="`woo-settings-${a.id}`"><Settings2 /> Cài đặt đồng bộ</Button></RouterLink>
                            <RouterLink v-else-if="a.marketplace !== 'fake'" :to="`/marketplaces/${a.id}/shop`"><Button variant="outline" :data-testid="`shop-settings-${a.id}`"><Settings2 /> Cài đặt đồng bộ</Button></RouterLink>
                            <RouterLink :to="`/marketplaces/${a.id}/listings`"><Button variant="outline"><Link2 /> Liên kết sản phẩm</Button></RouterLink>
                            <Button variant="outline" size="icon" aria-label="Thêm thao tác" :data-testid="`account-menu-${a.id}`" @click="menuFor = menuFor === a.id ? null : a.id"><MoreHorizontal /></Button>
                            <template v-if="menuFor === a.id">
                                <div class="fixed inset-0 z-30" @click="menuFor = null" />
                                <div class="absolute right-0 top-full z-40 mt-1 w-64 rounded-lg border bg-card p-1.5 text-sm shadow-lg" role="menu">
                                    <div v-if="tenant.can('marketplace.connect')" class="px-2 pb-2 pt-1">
                                        <p class="mb-1 text-xs text-muted-foreground">Kho xuất hàng</p>
                                        <Select :model-value="a.warehouse?.id ?? ''" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" aria-label="Kho xuất" @update:model-value="(v) => changeWarehouse(a, v)" />
                                    </div>
                                    <template v-if="tenant.can('marketplace.connect')">
                                        <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left hover:bg-muted" :disabled="busy === `sync-${a.id}`" @click="menuFor = null; syncProducts(a)"><RefreshCw class="size-4 text-muted-foreground" /> Tải lại sản phẩm từ sàn</button>
                                        <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left hover:bg-muted" :disabled="busy === `push-${a.id}`" @click="menuFor = null; pushStock(a)"><Send class="size-4 text-muted-foreground" /> Đẩy tồn kho ngay</button>
                                        <div class="my-1 border-t" />
                                        <button type="button" role="menuitem" class="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-rose-600 hover:bg-rose-50" @click="menuFor = null; disconnect(a)"><Unplug class="size-4" /> Ngắt kết nối</button>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div v-if="a.setup_pending && a.status === 'connected'" class="flex flex-wrap items-center gap-3 border-t bg-blue-50/60 px-5 py-3 text-sm">
                        <Wrench class="size-4 text-blue-600" />
                        <span class="flex-1">Chưa thiết lập xong: ghép sản phẩm, nhập tồn ban đầu rồi bật đồng bộ.</span>
                        <RouterLink v-if="tenant.can('marketplace.connect')" :to="`/marketplaces/${a.id}/setup`"><Button size="sm" :data-testid="`setup-${a.id}`">Thiết lập shop</Button></RouterLink>
                    </div>
                    <div v-else-if="health(a) === 'error'" class="flex flex-wrap items-center gap-3 border-t bg-rose-50/70 px-5 py-3 text-sm">
                        <AlertTriangle class="size-4 shrink-0 text-rose-600" />
                        <div class="min-w-0 flex-1">
                            <p>{{ a.circuit_open ? `${a.marketplace_name} báo lỗi nhiều lần nên đồng bộ đang tạm dừng.` : `Không đồng bộ được với ${a.marketplace_name}.` }} Đơn mới có thể chưa về.</p>
                            <p v-if="a.last_error" class="mt-0.5 truncate text-xs text-muted-foreground" :title="a.last_error">Chi tiết: {{ a.last_error }}</p>
                        </div>
                        <Button v-if="tenant.can('marketplace.connect') && a.marketplace !== 'fake'" size="sm" variant="destructive" @click="reconnect(a)">Kết nối lại</Button>
                    </div>
                    <div v-else-if="a.status === 'connected' && unlinked(a)" class="flex flex-wrap items-center gap-3 border-t bg-amber-50/70 px-5 py-3 text-sm">
                        <Link2Off class="size-4 shrink-0 text-amber-600" />
                        <span class="flex-1">{{ formatNumber(unlinked(a)) }} sản phẩm chưa liên kết với kho nên chưa tự trừ tồn.<template v-if="a.stats?.suggested"> Có {{ formatNumber(a.stats.suggested) }} gợi ý ghép sẵn.</template></span>
                        <RouterLink :to="`/marketplaces/${a.id}/listings`"><Button size="sm" class="bg-amber-500 text-white hover:bg-amber-600">Liên kết {{ formatNumber(unlinked(a)) }} sản phẩm</Button></RouterLink>
                    </div>
                    <div v-else-if="a.status !== 'connected'" class="flex flex-wrap items-center gap-3 border-t bg-slate-50 px-5 py-3 text-sm text-muted-foreground">
                        <Unplug class="size-4 shrink-0" />
                        <span class="flex-1">Đã ngắt kết nối. Đơn cũ vẫn được giữ.</span>
                        <Button v-if="tenant.can('marketplace.connect') && a.marketplace !== 'fake'" size="sm" variant="outline" @click="reconnect(a)">Kết nối lại</Button>
                    </div>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-3 text-base font-semibold">{{ sanName ? `Thêm gian hàng ${sanName}` : 'Thêm kênh bán' }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" data-testid="real-marketplaces">
                <div v-for="c in realMarketplaces" :key="c.key" :class="['flex flex-col rounded-xl border p-4 shadow-xs', ready(c) ? 'bg-card' : 'bg-slate-50/70']">
                    <div class="flex items-center gap-3">
                        <MarketplaceLogo :marketplace="c.key" :name="c.name" size="lg" />
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ c.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ ready(c) ? syncs(c) : 'Sắp có' }}</p>
                        </div>
                    </div>
                    <p v-if="c.capabilities['inventory.update'] !== 'supported'" class="mt-2 flex-1 text-xs text-muted-foreground" :data-testid="`stock-note-${c.key}`">
                        Tồn kho trên sàn: {{ c.capabilities['inventory.update'] === 'unsupported' ? 'sàn không cho cập nhật tự động' : 'chưa tự cập nhật được' }}, cập nhật tay trên Seller Center.
                    </p>
                    <span v-else class="flex-1" />
                    <Button
                        v-if="tenant.can('marketplace.connect')"
                        class="mt-3 w-full"
                        variant="outline"
                        :disabled="!c.available"
                        :loading="busy === `real-${c.key}`"
                        :data-testid="`connect-${c.key}`"
                        @click="connectReal(c)"
                    >
                        <Plus /> Kết nối
                    </Button>
                    <p v-if="connectNotice?.key === c.key" class="mt-2 text-xs text-destructive" :data-testid="`connect-notice-${c.key}`">{{ connectNotice.message }}</p>
                </div>
            </div>
            <p class="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground"><ShieldCheck class="size-4 text-emerald-600" /> Kết nối qua trang đăng nhập chính thức của sàn, không cần mật khẩu.</p>
        </section>

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

        <details class="group rounded-xl border bg-card shadow-xs" data-testid="tech-details">
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
