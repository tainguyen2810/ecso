<script setup lang="ts">
import { computed, onMounted, provide, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, CalendarSync, Check, History, Info, KeyRound, Link2, RefreshCw, ShoppingBag, ShoppingCart, Trash2, Unplug, Zap } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import DateInput from '@/shared/ui/DateInput.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import BackfillDialog from './BackfillDialog.vue';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import { ago, btn, card, daysLeft, n, type ChannelStats } from './channel';
import { cn } from '@/shared/lib/utils';
import type { MarketplaceAccountData, Warehouse } from '@/shared/types';

/** Order sync settings of a Shopee/TikTok (or other marketplace) shop: "Lấy đơn từ", reconcile interval, warehouse, history. */
interface SyncRunRow {
    id: string;
    trigger: string;
    status: string;
    window_from: string | null;
    window_to: string | null;
    counts: Record<string, number>;
    error: string | null;
    started_at: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
provide('soft-cards', true);

const account = ref<MarketplaceAccountData | null>(null);
const overview = ref({ orders_today: 0, orders_total: 0, first_order_at: null as string | null, last_webhook_at: null as string | null, sync_errors: 0 });
const warehouses = ref<Warehouse[]>([]);
const runs = ref<SyncRunRow[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const backfillOpen = ref(false);
const canEdit = computed(() => tenant.can('marketplace.connect'));

/** Draft of the settings card, saved together with "Lưu". */
const since = ref<string>('3');
const sinceDate = ref<string | null>(null);
const interval = ref('15');
const warehouseId = ref('');
const syncOrders = ref(true);
const syncStock = ref(true);
const pushStatus = ref(true);
const importNew = ref(true);
const syncPrices = ref(false);
/** What was loaded, to show the save bar only when something changed. */
const saved = ref('');
const draft = computed(() => JSON.stringify([since.value, sinceDate.value, interval.value, warehouseId.value, syncOrders.value, syncStock.value, pushStatus.value, importNew.value, syncPrices.value]));
const dirty = computed(() => saved.value !== '' && saved.value !== draft.value);

const presets = [
    { key: '0', label: 'Chỉ đơn mới', hint: 'từ lúc kết nối' },
    { key: '1', label: '1 ngày trước' },
    { key: '3', label: '3 ngày trước', hint: 'mặc định' },
    { key: '7', label: '7 ngày trước' },
    { key: '30', label: '30 ngày trước' },
    { key: 'custom', label: 'Chọn ngày…' },
];
const intervals = [5, 15, 30, 60].map((m) => ({ value: String(m), label: m === 60 ? '1 giờ' : `${m} phút` }));
const DAY = 86_400_000;

/** The preset the saved moment matches, counted back from the moment the shop was connected. */
function presetOf(a: MarketplaceAccountData): string {
    if (!a.orders_since) return '3';
    const base = new Date(a.connected_at ?? a.orders_since).getTime();
    const days = (base - new Date(a.orders_since).getTime()) / DAY;
    const hit = ['0', '1', '3', '7', '30'].find((d) => Math.abs(days - Number(d)) < 0.05);
    return hit ?? 'custom';
}
function fill(a: MarketplaceAccountData) {
    account.value = a;
    since.value = presetOf(a);
    sinceDate.value = a.orders_since ? a.orders_since.slice(0, 10) : null;
    interval.value = String(a.order_sync_interval ?? 15);
    warehouseId.value = a.warehouse?.id ?? '';
    syncOrders.value = a.settings?.sync_orders ?? true;
    syncStock.value = a.sync_enabled;
    pushStatus.value = a.settings?.push_status ?? true;
    importNew.value = a.settings?.import_new_products ?? true;
    syncPrices.value = a.settings?.sync_prices ?? false;
    saved.value = draft.value;
}

/** What "Lưu" would store as the moment. */
const sinceValue = computed<string | null>(() => {
    const a = account.value;
    if (!a) return null;
    if (since.value === 'custom') return sinceDate.value;
    const base = new Date(a.connected_at ?? Date.now()).getTime();
    return new Date(base - Number(since.value) * DAY).toISOString();
});
const yearAgo = new Date(Date.now() - 365 * DAY).toISOString().slice(0, 10);
const today = new Date().toISOString().slice(0, 10);

async function loadRuns() {
    const { data } = await api.get('/sync-runs', { params: { account: route.params.id } });
    runs.value = (data.data as (SyncRunRow & { resource: string })[]).filter((r) => r.resource === 'orders').slice(0, 8);
}

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const id = route.params.id as string;
        const [a, w] = await Promise.all([api.get(`/marketplace-accounts/${id}`), api.get('/warehouses')]);
        fill(a.data.data);
        overview.value = a.data.overview;
        warehouses.value = w.data.data.filter((x: Warehouse) => x.status === 'active' && x.purpose !== 'defective');
        await Promise.all([loadRuns().catch(() => {}), loadStats()]);
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

/** Token expiry of this shop (Sức khoẻ kết nối), from the Kênh bán numbers. */
const tokenExpiresAt = ref<string | null>(null);
async function loadStats() {
    try {
        const { data } = await api.get('/marketplace-stats', { params: { marketplace: account.value?.marketplace, days: 1 } });
        tokenExpiresAt.value = (data.data as ChannelStats).accounts.find((x) => x.id === account.value?.id)?.token_expires_at ?? null;
    } catch {
        tokenExpiresAt.value = null;
    }
}
const tokenDays = computed(() => daysLeft(tokenExpiresAt.value));
const remove = () => run('remove', async () => {
    if (!window.confirm(`Xoá kênh "${account.value!.shop_name}" khỏi danh sách?\n\nSản phẩm sàn và liên kết của kênh bị xoá. Đơn hàng cũ vẫn được giữ để báo cáo không đổi.`)) return;
    await api.delete(`/marketplace-accounts/${account.value!.id}`);
    await router.push('/marketplaces');
    return `Đã xoá kênh ${account.value!.shop_name}.`;
});
const switches = computed(() => [
    { model: syncOrders, label: 'Lấy đơn hàng', hint: 'Đơn mới và đơn đổi trạng thái về ngay qua thông báo của sàn', testid: 'shop-switch-orders' },
    { model: syncStock, label: 'Tự cập nhật tồn kho lên sàn', hint: 'Mỗi khi tồn đổi: bán ở kênh khác, nhập hàng, kiểm kho', testid: 'shop-switch-stock' },
    { model: pushStatus, label: 'Đẩy trạng thái đơn', hint: 'Xác nhận, chuẩn bị hàng, in phiếu giao từ phần mềm', testid: 'shop-switch-status' },
    { model: importNew, label: 'Tự tạo sản phẩm khi shop đăng sản phẩm mới', hint: 'Tắt: sản phẩm mới vào danh sách chờ duyệt', testid: 'shop-switch-import' },
    { model: syncPrices, label: 'Đồng bộ giá', hint: 'Giữ tắt nếu bạn chỉnh giá trực tiếp trên sàn', testid: 'shop-switch-prices' },
]);
const runTone = (r: SyncRunRow) => (r.error ? 'bg-red-500' : r.status === 'running' ? 'bg-amber-500' : r.trigger === 'backfill' ? 'bg-violet-500' : total(r.counts) ? 'bg-emerald-500' : 'bg-slate-300');

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

const save = () => run('save', async () => {
    if (since.value === 'custom' && !sinceDate.value) throw new Error('Chọn ngày bắt đầu lấy đơn.');
    const { data } = await api.patch(`/marketplace-accounts/${account.value!.id}`, {
        orders_since: sinceValue.value,
        warehouse_id: warehouseId.value || undefined,
        sync_enabled: syncStock.value,
        settings: { order_sync_interval: Number(interval.value), sync_orders: syncOrders.value, push_status: pushStatus.value, import_new_products: importNew.value, sync_prices: syncPrices.value },
    });
    fill({ ...account.value!, ...data.data });
    return 'Đã lưu cài đặt đồng bộ.';
});
const syncNow = () => run('sync-orders', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/sync-orders`);
    const c = data.data.counts as Record<string, number>;
    await load();
    return `Đã lấy đơn: ${formatNumber(c.created ?? 0)} đơn mới, ${formatNumber(c.updated ?? 0)} đơn cập nhật${c.failed ? `, ${formatNumber(c.failed)} đơn lỗi` : ''}.`;
});
const resync = () => run('sync', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/sync-products`);
    await load();
    return `Đã lấy ${formatNumber(data.data.variants)} SKU từ ${formatNumber(data.data.listings)} sản phẩm.`;
});
const disconnect = () => run('disconnect', async () => {
    if (!window.confirm('Ngắt kết nối shop? Đơn cũ được giữ.')) return;
    await api.post(`/marketplace-accounts/${account.value!.id}/disconnect`);
    await router.push('/marketplaces');
    return 'Đã ngắt kết nối shop.';
});

const isGateway = computed(() => ['shopee', 'tiktok'].includes(account.value?.marketplace ?? ''));
const statusLabel = computed(() => (account.value?.status !== 'connected' ? 'Đã ngắt' : account.value.last_error ? 'Có lỗi' : 'Đang hoạt động'));
const triggerLabel: Record<string, string> = { schedule: 'Tự động', user: 'Bấm tay', backfill: 'Lấy lại' };
const short = (iso: string | null) => (iso ? new Date(iso).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const dayOnly = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' }) : '—');
const hm = (iso: string) => new Date(iso).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
const span = (r: SyncRunRow) => {
    if (!r.window_from || !r.window_to) return '—';
    const hours = (new Date(r.window_to).getTime() - new Date(r.window_from).getTime()) / 3_600_000;
    return hours > 24 ? `${dayOnly(r.window_from)} – ${dayOnly(r.window_to)}` : `${hm(r.window_from)} – ${hm(r.window_to)}`;
};
const total = (c: Record<string, number>) => Object.entries(c).reduce((s, [k, v]) => (k === 'skipped' ? s : s + v), 0);
</script>

<template>
    <div v-if="loading && !account" class="space-y-4"><Skeleton class="h-16" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="account">
        <ChannelHead
            :title="account.shop_name"
            :marketplace="account.marketplace"
            :crumbs="[{ label: account.marketplace_name, to: `/marketplaces?san=${account.marketplace}` }]"
            :description="`${account.marketplace_name} · Shop ID ${account.external_shop_id}${account.connected_at ? ` · kết nối từ ${formatDateTime(account.connected_at).split(' ').pop()}` : ''}${runs[0] ? ` · lấy đơn lần cuối ${formatDateTime(runs[0].started_at)}` : ''}`"
        >
            <template #chips>
                <span :class="cn('inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[12px] font-medium', account.status === 'connected' && !account.last_error ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600')">
                    <i :class="cn('size-1.5 rounded-full', account.status === 'connected' && !account.last_error ? 'bg-emerald-500' : 'bg-red-500')" />{{ statusLabel }}
                </span>
            </template>
            <template v-if="canEdit && account.status === 'connected'">
                <button type="button" :class="btn.outline" :disabled="busy === 'sync-orders'" data-testid="shop-sync-orders" @click="syncNow"><ShoppingCart :class="cn('size-4', busy === 'sync-orders' && 'animate-pulse')" /> Đồng bộ đơn ngay</button>
                <button type="button" :class="btn.outline" data-testid="shop-backfill" @click="backfillOpen = true"><CalendarSync class="size-4" /> Lấy lại đơn theo ngày</button>
                <button type="button" :class="btn.outline" :disabled="busy === 'sync'" @click="resync"><RefreshCw :class="cn('size-4', busy === 'sync' && 'animate-spin')" /> Tải sản phẩm</button>
            </template>
            <RouterLink :to="`/marketplaces/${account.id}/listings`" :class="btn.primary"><Link2 class="size-4" /> Liên kết sản phẩm</RouterLink>
        </ChannelHead>

        <p v-if="account.last_error" class="mb-5 flex items-center gap-2 rounded-[14px] border border-rose-200 bg-rose-50/70 px-5 py-3 text-sm"><AlertTriangle class="size-4 text-rose-500" /> {{ account.last_error }}</p>

        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="shop-overview">
            <ReportKpi label="Đơn đã lấy về" :icon="History" tone="blue" plain :value="overview.orders_total" unit="">
                <template #chips><ReportChip tone="up">{{ n(overview.orders_today) }} hôm nay</ReportChip></template>
                {{ overview.first_order_at ? `từ ${dayOnly(overview.first_order_at)} · ` : '' }}đơn trùng không bị nhân đôi
            </ReportKpi>
            <ReportKpi label="Sản phẩm đã liên kết" :icon="Link2" tone="violet" plain :value="null" :text="`${n(account.stats?.mapped ?? 0)} / ${n(account.stats?.listings ?? 0)}`" unit="">
                <template #chips><RouterLink :to="`/marketplaces/${account.id}/listings`"><ReportChip tone="blue">Liên kết sản phẩm →</ReportChip></RouterLink></template>
                {{ (account.stats?.listings ?? 0) - (account.stats?.mapped ?? 0) ? `${n((account.stats?.listings ?? 0) - (account.stats?.mapped ?? 0))} mã chưa nối với kho` : 'Mọi mã đã nối với kho' }}
            </ReportKpi>
            <ReportKpi label="Đơn hôm nay" :icon="ShoppingBag" tone="green" plain :value="overview.orders_today" unit="">
                {{ overview.last_webhook_at ? `Sàn báo đơn lần cuối ${ago(overview.last_webhook_at)}` : 'Chưa nhận thông báo nào từ sàn' }}
            </ReportKpi>
            <ReportKpi label="Lỗi đồng bộ đang mở" :icon="AlertTriangle" :tone="overview.sync_errors ? 'amber' : 'green'" plain :value="overview.sync_errors" unit="">
                <template v-if="overview.sync_errors" #chips><RouterLink :to="`/marketplaces/sync?tab=log&account=${account.id}`"><ReportChip tone="amber">Xem lỗi →</ReportChip></RouterLink></template>
                {{ overview.sync_errors ? 'Đơn hoặc tồn chưa đồng bộ được' : 'Không có đơn hay tồn lỗi' }}
            </ReportKpi>
        </div>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_420px] 2xl:grid-cols-[minmax(0,1fr)_440px]">
            <div class="min-w-0">
                <section :class="card" data-testid="shop-sync-settings">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">Đồng bộ đơn hàng</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">Đơn mới về ngay qua thông báo của sàn, đối soát lại định kỳ để không sót đơn</p>
                        </div>
                        <span v-if="isGateway" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700"><Zap class="size-3" /> Realtime</span>
                    </header>
                    <div class="space-y-5 p-5">
                        <div>
                            <p class="mb-2 text-[14px] font-medium text-slate-900">Lấy đơn từ</p>
                            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3" role="radiogroup" data-testid="shop-since">
                                <label
                                    v-for="p in presets"
                                    :key="p.key"
                                    :class="cn('flex cursor-pointer items-start gap-2.5 rounded-[12px] border px-4 py-3 text-[14px]', since === p.key ? 'border-blue-500 bg-blue-50/60 ring-2 ring-blue-100' : 'border-slate-200 hover:border-blue-200', !canEdit && 'pointer-events-none opacity-70')"
                                >
                                    <input v-model="since" type="radio" name="since" :value="p.key" class="mt-0.5 accent-blue-600" :disabled="!canEdit" :data-testid="`since-${p.key}`" />
                                    <span class="font-medium text-slate-900">{{ p.label }}<span v-if="p.hint" class="block text-[12px] font-normal text-slate-500">{{ p.hint }}</span></span>
                                </label>
                            </div>
                            <DateInput v-if="since === 'custom'" v-model="sinceDate" class="mt-2 sm:w-56" :min="yearAgo" :max="today" :disabled="!canEdit" data-testid="since-date" />
                            <p class="mt-2 text-[12px] text-slate-500">
                                <template v-if="sinceValue">Mốc: <b class="text-slate-700">{{ formatDateTime(sinceValue) }}</b>. </template>
                                Đơn tạo trước mốc này không được lấy vào. Đơn đã lấy về vẫn giữ nguyên. Cần đơn cũ hơn, dùng “Lấy lại đơn theo ngày”.
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Tự đối soát đơn mỗi" hint="Lưới an toàn: vớt lại đơn nếu sàn gửi sót thông báo.">
                                <Select v-model="interval" :disabled="!canEdit" :options="intervals" data-testid="shop-interval" />
                            </FormField>
                            <FormField label="Kho trừ hàng">
                                <Select v-model="warehouseId" :disabled="!canEdit" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" />
                            </FormField>
                        </div>

                        <div class="divide-y divide-slate-100 rounded-[12px] bg-slate-50 px-4">
                            <Switch v-for="sw in switches" :key="sw.testid" v-model="sw.model.value" :label="sw.label" :hint="sw.hint" :disabled="!canEdit" :data-testid="sw.testid" />
                        </div>
                    </div>
                </section>
                <div v-if="canEdit" :class="cn('mt-4 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border bg-white px-5 py-3.5', dirty ? 'border-blue-200' : 'border-slate-200/80')">
                    <p class="flex items-center gap-1.5 text-[14px] text-slate-600"><Info class="size-4 text-blue-600" /> {{ dirty ? 'Bạn đã đổi cài đặt, bấm Lưu để áp dụng.' : 'Cài đặt đang được áp dụng.' }}</p>
                    <div class="flex gap-2">
                        <button v-if="dirty" type="button" :class="btn.small" @click="fill(account)">Huỷ</button>
                        <button type="button" :class="btn.smallPrimary" :disabled="busy === 'save'" data-testid="shop-save" @click="save"><Check class="size-3.5" /> Lưu</button>
                    </div>
                </div>
            </div>

            <aside class="min-w-0 space-y-5">
                <section :class="card" data-testid="shop-health">
                    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Sức khoẻ kết nối</h3>
                    </header>
                    <div class="divide-y divide-slate-100 px-5 py-1">
                        <div class="flex items-center justify-between py-3 text-[14px]">
                            <span class="text-slate-600">Ủy quyền {{ account.marketplace_name }}</span>
                            <span class="flex items-center gap-2 font-medium"><i :class="cn('size-2 rounded-full', tokenDays === null ? 'bg-slate-300' : tokenDays <= 14 ? 'bg-amber-500' : 'bg-emerald-500')" />{{ tokenDays === null ? 'Không hết hạn' : tokenDays <= 0 ? 'Hết hạn hôm nay' : `Còn ${n(tokenDays)} ngày` }}</span>
                        </div>
                        <div class="flex items-center justify-between py-3 text-[14px]">
                            <span class="text-slate-600">Thông báo từ sàn</span>
                            <span class="flex items-center gap-2 font-medium"><i :class="cn('size-2 rounded-full', overview.last_webhook_at ? 'bg-emerald-500' : 'bg-slate-300')" />{{ overview.last_webhook_at ? `Nhận ${ago(overview.last_webhook_at)}` : 'Chưa nhận' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-3 text-[14px]">
                            <span class="text-slate-600">Đối soát đơn</span>
                            <span class="flex items-center gap-2 font-medium"><i :class="cn('size-2 rounded-full', runs[0]?.error ? 'bg-red-500' : runs[0] ? 'bg-emerald-500' : 'bg-slate-300')" />{{ runs[0] ? ago(runs[0].started_at) : 'Chưa chạy' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-3 text-[14px]">
                            <span class="text-slate-600">Đẩy tồn lên sàn</span>
                            <span class="flex items-center gap-2 font-medium"><i :class="cn('size-2 rounded-full', account.sync_enabled ? 'bg-emerald-500' : 'bg-slate-300')" />{{ account.sync_enabled ? (account.stats?.pending_push ? `${n(account.stats.pending_push)} SKU đang chờ` : 'Đang bật') : 'Đang tắt' }}</span>
                        </div>
                    </div>
                    <p v-if="tokenDays !== null && tokenDays <= 14" class="flex items-center gap-2 border-t border-amber-100 bg-amber-50/60 px-5 py-3 text-[13px] text-amber-800"><KeyRound class="size-4" /> Kết nối lại trước khi hết hạn để không mất đơn (Kênh bán › Kết nối).</p>
                </section>

                <section :class="card" data-testid="shop-runs">
                    <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">Lịch sử lấy đơn</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">Đơn trùng mã không bị nhân đôi, chỉ cập nhật đơn đã có</p>
                        </div>
                        <RouterLink :to="`/marketplaces/sync?tab=log&account=${account.id}`" class="whitespace-nowrap text-[13px] font-medium text-blue-600">Xem tất cả →</RouterLink>
                    </header>
                    <p v-if="!runs.length" class="px-5 py-6 text-sm text-muted-foreground">Chưa có lần lấy đơn nào.</p>
                    <div v-else class="relative px-5 py-4">
                        <span class="absolute bottom-6 left-[24px] top-6 w-px bg-slate-200" />
                        <div v-for="r in runs" :key="r.id" class="relative flex gap-3 pb-4 last:pb-0">
                            <i :class="cn('relative z-10 mt-1.5 size-2.5 shrink-0 rounded-full ring-4 ring-white', runTone(r))" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-[14px] font-medium text-slate-900">
                                        {{ triggerLabel[r.trigger] ?? r.trigger }} · {{ span(r) }}
                                        <Badge v-if="r.status === 'running'" tone="warning" class="ml-1">Đang chạy</Badge>
                                    </p>
                                    <span class="whitespace-nowrap text-[12px] text-slate-400">{{ short(r.started_at) }}</span>
                                </div>
                                <p :class="cn('text-[13px]', r.error ? 'text-red-600' : 'text-slate-500')" :title="r.error ?? undefined">
                                    {{ r.error ? 'Lỗi khi lấy đơn' : `${n(total(r.counts))} đơn` }}<template v-if="!r.error && r.counts.created"> · mới {{ n(r.counts.created) }}</template>
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="canEdit && account.status === 'connected'" :class="cn(card, 'p-5')" data-testid="shop-danger">
                    <h3 class="text-[16px] font-semibold text-slate-900">Ngắt hoặc xoá kênh</h3>
                    <p class="mt-1 text-[13px] text-slate-500">Ngắt kết nối giữ nguyên đơn cũ. Xoá kênh gỡ sản phẩm sàn và liên kết, đơn cũ vẫn giữ cho báo cáo.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" :class="btn.small" :disabled="busy === 'disconnect'" @click="disconnect"><Unplug class="size-3.5" /> Ngắt kết nối</button>
                        <button type="button" :class="cn(btn.small, 'border-red-200 text-red-600 hover:border-red-300 hover:text-red-700')" :disabled="busy === 'remove'" @click="remove"><Trash2 class="size-3.5" /> Xoá kênh</button>
                    </div>
                </section>
            </aside>
        </div>

        <BackfillDialog v-model:open="backfillOpen" :account="account" @progress="loadRuns" @done="load" />
    </template>
</template>
