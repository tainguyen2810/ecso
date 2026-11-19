<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ChevronLeft, CloudDownload, History, Link2, RefreshCw, ShoppingCart, Unplug, Zap } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import DateInput from '@/shared/ui/DateInput.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StatTile from '@/shared/ui/StatTile.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import BackfillDialog from './BackfillDialog.vue';
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
        await loadRuns().catch(() => {});
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

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
        settings: { order_sync_interval: Number(interval.value), sync_orders: syncOrders.value },
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
    <RouterLink to="/marketplaces" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Kết nối sàn</RouterLink>

    <div v-if="loading && !account" class="space-y-4"><Skeleton class="h-16" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="account">
        <div class="mb-5 flex items-center gap-3">
            <MarketplaceLogo :marketplace="account.marketplace" :name="account.marketplace_name" size="lg" class="size-11" />
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ account.shop_name }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ account.marketplace_name }} · Shop ID {{ account.external_shop_id }} ·
                    <span :class="account.status === 'connected' && !account.last_error ? 'text-success' : 'text-destructive'">{{ statusLabel }}</span>
                    <template v-if="runs[0]"> · lấy đơn lần cuối {{ formatDateTime(runs[0].started_at) }}</template>
                </p>
            </div>
        </div>
        <div v-if="canEdit && account.status === 'connected'" class="mb-4 flex flex-wrap gap-2">
            <Button variant="outline" :loading="busy === 'sync-orders'" data-testid="shop-sync-orders" @click="syncNow"><ShoppingCart /> Đồng bộ đơn ngay</Button>
            <Button variant="outline" data-testid="shop-backfill" @click="backfillOpen = true"><CloudDownload /> Lấy lại đơn theo ngày…</Button>
            <Button variant="outline" :loading="busy === 'sync'" @click="resync"><RefreshCw /> Tải sản phẩm</Button>
            <Button variant="outline" class="text-destructive" :loading="busy === 'disconnect'" @click="disconnect"><Unplug /> Ngắt</Button>
        </div>

        <p v-if="account.last_error" class="mb-4 flex items-center gap-2 rounded-md border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm"><AlertTriangle class="size-4 text-rose-500" /> {{ account.last_error }}</p>

        <Card class="mb-4" data-testid="shop-overview">
            <div class="grid divide-y sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
                <StatTile :icon="Link2" label="Sản phẩm đã liên kết" :value="`${formatNumber(account.stats?.mapped ?? 0)} / ${formatNumber(account.stats?.listings ?? 0)}`" :to="`/marketplaces/${account.id}/listings`" />
                <StatTile :icon="ShoppingCart" label="Đơn hôm nay" :value="formatNumber(overview.orders_today)" tone="green" />
                <StatTile :icon="History" label="Đơn đã lấy về" :value="formatNumber(overview.orders_total)"><template v-if="overview.first_order_at">từ {{ dayOnly(overview.first_order_at) }}</template></StatTile>
                <StatTile :icon="AlertTriangle" label="Lỗi đồng bộ" :value="formatNumber(overview.sync_errors)" :tone="overview.sync_errors ? 'red' : 'slate'" :to="`/marketplaces/sync?tab=log&account=${account.id}`" />
            </div>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card title="Đồng bộ đơn hàng" data-testid="shop-sync-settings">
                <div class="space-y-5 px-5 py-4">
                    <p v-if="isGateway" class="flex gap-2 rounded-md bg-amber-50/60 px-3 py-2.5 text-sm">
                        <Zap class="mt-0.5 size-4 shrink-0 text-amber-500" />
                        <span><b>Realtime</b>: đơn mới và đơn đổi trạng thái về ngay qua thông báo của sàn.<template v-if="overview.last_webhook_at"> Nhận lần cuối {{ formatDateTime(overview.last_webhook_at) }}.</template></span>
                    </p>

                    <div>
                        <p class="mb-2 text-sm font-medium">Lấy đơn từ</p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" data-testid="shop-since">
                            <label
                                v-for="p in presets"
                                :key="p.key"
                                :class="['flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm', since === p.key ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50', !canEdit && 'pointer-events-none opacity-70']"
                            >
                                <input v-model="since" type="radio" name="since" :value="p.key" class="mt-0.5" :disabled="!canEdit" :data-testid="`since-${p.key}`" />
                                <span>{{ p.label }}<span v-if="p.hint" class="block text-xs text-muted-foreground">{{ p.hint }}</span></span>
                            </label>
                        </div>
                        <DateInput v-if="since === 'custom'" v-model="sinceDate" class="mt-2 sm:w-56" :min="yearAgo" :max="today" :disabled="!canEdit" data-testid="since-date" />
                        <p class="mt-2 text-xs text-muted-foreground">
                            <template v-if="sinceValue">Mốc: <b class="text-foreground">{{ formatDateTime(sinceValue) }}</b>. </template>
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
                </div>
                <div class="divide-y border-t px-5 py-2">
                    <Switch v-model="syncOrders" label="Lấy đơn hàng" hint="Đơn mới và đơn đổi trạng thái" :disabled="!canEdit" data-testid="shop-switch-orders" />
                    <Switch v-model="syncStock" label="Tự cập nhật tồn kho lên sàn" hint="Khi tồn trong kho thay đổi" :disabled="!canEdit" data-testid="shop-switch-stock" />
                </div>
                <div v-if="canEdit" class="flex justify-end border-t px-5 py-3">
                    <Button :loading="busy === 'save'" data-testid="shop-save" @click="save">Lưu</Button>
                </div>
            </Card>

            <Card title="Lịch sử lấy đơn" data-testid="shop-runs">
                <template #actions>
                    <RouterLink :to="`/marketplaces/sync?tab=log&account=${account.id}`" class="text-sm text-primary hover:underline">Xem tất cả</RouterLink>
                </template>
                <p v-if="!runs.length" class="px-5 py-6 text-sm text-muted-foreground">Chưa có lần lấy đơn nào.</p>
                <table v-else class="w-full text-sm">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-5 py-2 font-medium">Lúc</th><th class="px-3 py-2 font-medium">Khoảng</th><th class="px-3 py-2 font-medium">Kiểu</th><th class="px-5 py-2 text-right font-medium">Đơn</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in runs" :key="r.id">
                            <td class="px-5 py-2.5 tabular-nums">{{ short(r.started_at) }}</td>
                            <td class="px-3 py-2.5 tabular-nums">{{ span(r) }}</td>
                            <td class="px-3 py-2.5">
                                <Badge v-if="r.trigger === 'backfill'" tone="info">Lấy lại</Badge>
                                <span v-else class="text-muted-foreground">{{ triggerLabel[r.trigger] ?? r.trigger }}</span>
                                <Badge v-if="r.status === 'running'" tone="warning" class="ml-1">Đang chạy</Badge>
                                <span v-else-if="r.error" class="ml-1 text-xs text-destructive" :title="r.error">lỗi</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums">
                                {{ formatNumber(total(r.counts)) }}
                                <span v-if="r.trigger === 'backfill' && r.counts.created" class="text-xs text-muted-foreground">(mới {{ formatNumber(r.counts.created) }})</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t px-5 py-3 text-xs text-muted-foreground">Đơn trùng mã không bị nhân đôi: lấy lại chỉ cập nhật đơn đã có.</p>
            </Card>
        </div>

        <BackfillDialog v-model:open="backfillOpen" :account="account" @progress="loadRuns" @done="load" />
    </template>
</template>
