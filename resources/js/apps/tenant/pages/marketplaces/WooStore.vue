<script setup lang="ts">
import { computed, onMounted, provide, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, CircleCheck, History, Link2, ListChecks, PackagePlus, RefreshCw, Send, ShoppingCart, Tag, Unplug, Webhook } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import { btn } from './channel';
import { cn } from '@/shared/lib/utils';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { orderStatus } from '../orders/labels';
import type { MarketplaceAccountData, MarketplaceSyncSettings, NewListingData, PendingPriceChange, StatusMapData, Warehouse } from '@/shared/types';

/** Settings page of a connected WooCommerce store: sync switches, warehouse, new store products and website prices waiting for approval. */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
provide('soft-cards', true);

const account = ref<MarketplaceAccountData | null>(null);
const overview = ref({ orders_this_week: 0, new_products: 0, pending_prices: 0, sync_errors: 0 });
const pending = ref<NewListingData[]>([]);
const prices = ref<PendingPriceChange[]>([]);
const warehouses = ref<Warehouse[]>([]);
const statusMap = ref<StatusMapData | null>(null);
const mapDraft = ref<Record<string, string>>({});
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const canEdit = computed(() => tenant.can('marketplace.connect'));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const id = route.params.id as string;
        const [a, p, w, pr] = await Promise.all([
            api.get(`/marketplace-accounts/${id}`),
            api.get(`/marketplace-accounts/${id}/new-products`),
            api.get('/warehouses'),
            api.get(`/marketplace-accounts/${id}/price-changes`),
        ]);
        api.get(`/marketplace-accounts/${id}/status-map`).then(({ data }) => setStatusMap(data.data)).catch(() => {});
        account.value = a.data.data;
        overview.value = a.data.overview;
        pending.value = p.data.data;
        prices.value = pr.data.data;
        warehouses.value = w.data.data.filter((x: Warehouse) => x.status === 'active' && x.purpose !== 'defective');
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    await load();
    // "Ánh xạ trạng thái" link from an order: scroll to the card once it is on the page.
    if (route.hash === '#status-map') setTimeout(() => document.getElementById('status-map')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
});

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

const switches: { key: keyof MarketplaceSyncSettings; label: string; hint: string }[] = [
    { key: 'sync_orders', label: 'Đơn hàng Woo về phần mềm', hint: 'Vào Danh sách đơn hàng, nguồn Website, giữ hàng ngay' },
    { key: 'sync_stock', label: 'Tồn kho lên Woo', hint: 'Số có thể bán của kho bên dưới' },
    { key: 'sync_prices', label: 'Giá hai chiều', hint: 'Theo nguồn giá từng sản phẩm; giá khuyến mãi Woo là giá bán thực tế' },
    { key: 'import_new_products', label: 'Sản phẩm mới trên Woo', hint: 'Vào danh sách chờ duyệt' },
    { key: 'push_status', label: 'Trạng thái đơn hai chiều', hint: 'Xác nhận, đóng gói, bàn giao, huỷ trong phần mềm được ghi lên website. Tắt: chỉ website → phần mềm' },
];

const intervals = [5, 15, 30, 60].map((m) => ({ value: String(m), label: m === 60 ? '1 giờ' : `${m} phút` }));
const setSyncInterval = (value: string | undefined) => run('interval', async () => {
    const { data } = await api.patch(`/marketplace-accounts/${account.value!.id}`, { settings: { order_sync_interval: Number(value) } });
    account.value = { ...account.value!, order_sync_interval: data.data.order_sync_interval };
    return `EzChat Bán hàng sẽ đối soát đơn với website mỗi ${data.data.order_sync_interval} phút.`;
});
const syncOrders = () => run('sync-orders', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/sync-orders`);
    const c = data.data.counts as Record<string, number>;
    await load();
    return `Đã đối soát: ${formatNumber(c.created ?? 0)} đơn mới, ${formatNumber(c.updated ?? 0)} đơn cập nhật${c.failed ? `, ${formatNumber(c.failed)} đơn lỗi` : ''}.`;
});
const checkWebhooks = () => run('webhooks', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/check-webhooks`);
    account.value = { ...account.value!, webhook_health: data.data };
    return data.data.ok ? 'Nhận đơn tức thì từ website đang hoạt động.' : undefined;
});
const topicLabels: Record<string, string> = {
    'order.created': 'Đơn mới', 'order.updated': 'Đơn cập nhật', 'order.deleted': 'Đơn bị xóa', 'order.restored': 'Đơn khôi phục',
    'product.created': 'Sản phẩm mới', 'product.updated': 'Sản phẩm sửa', 'product.deleted': 'Sản phẩm xóa',
};

function setStatusMap(m: StatusMapData) {
    statusMap.value = m;
    // Statuses a plugin added come pre-filled with a guess from their name; nothing changes until "Lưu".
    mapDraft.value = { ...(m.suggested ?? {}), ...m.custom };
}
/** Raw statuses to show: the ones orders came with, the store's own entries and the shared defaults. */
const mapRows = computed(() => {
    const m = statusMap.value;
    if (!m) return [];
    const raws = new Set([...m.seen.map((x) => x.raw_status), ...Object.keys(m.custom), ...Object.keys(m.defaults), ...Object.keys(m.labels ?? {})]);
    return [...raws].sort((a, b) => (m.seen.find((x) => x.raw_status === b)?.unknown ?? 0) - (m.seen.find((x) => x.raw_status === a)?.unknown ?? 0) || a.localeCompare(b)).map((raw) => ({
        raw,
        label: m.labels?.[raw] ?? null,
        suggested: !m.custom[raw] && m.suggested?.[raw] && mapDraft.value[raw] === m.suggested[raw] ? m.suggested[raw] : null,
        fallback: m.defaults[raw] ?? null,
        seen: m.seen.find((x) => x.raw_status === raw) ?? null,
    }));
});
const newRaw = ref('');
const saveMap = () => run('status-map', async () => {
    const custom = statusMap.value?.custom ?? {};
    const map: Record<string, string | null> = {};
    for (const [raw, status] of Object.entries(mapDraft.value)) if (status && status !== custom[raw]) map[raw] = status;
    for (const raw of Object.keys(custom)) if (!mapDraft.value[raw]) map[raw] = null;
    const { data } = await api.put(`/marketplace-accounts/${account.value!.id}/status-map`, { map });
    setStatusMap(data.data);
    return data.data.orders_moved ? `Đã lưu ánh xạ và chuyển ${formatNumber(data.data.orders_moved)} đơn đang chưa ánh xạ.` : 'Đã lưu ánh xạ trạng thái.';
});
function addRaw() {
    const raw = newRaw.value.trim().toLowerCase().replace(/^wc-/, '');
    if (!raw || !statusMap.value) return;
    if (!statusMap.value.seen.some((x) => x.raw_status === raw)) statusMap.value.seen.push({ raw_status: raw, orders: 0, unknown: 0 });
    newRaw.value = '';
}

const setSwitch = (key: keyof MarketplaceSyncSettings, value: boolean) => run(`set-${key}`, async () => {
    const { data } = await api.patch(`/marketplace-accounts/${account.value!.id}`, { settings: { [key]: value } });
    account.value = { ...account.value!, settings: data.data.settings };
    if (key === 'sync_prices' && value) return 'Đã bật giá hai chiều. EzChat Bán hàng đang đọc lại giá trên website.';
});
const setWarehouse = (id: string | undefined) => run('warehouse', async () => {
    const { data } = await api.patch(`/marketplace-accounts/${account.value!.id}`, { warehouse_id: id });
    account.value = { ...account.value!, warehouse: data.data.warehouse };
    return 'Đã đổi kho trừ hàng cho đơn Woo.';
});
const resync = () => run('sync', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/sync-products`);
    await load();
    return `Đã lấy ${formatNumber(data.data.variants)} SKU từ ${formatNumber(data.data.listings)} sản phẩm.`;
});
const pushStock = () => run('push', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/push-stock`);
    return `Đã xếp hàng đẩy tồn cho ${formatNumber(data.data.queued)} SKU.`;
});
const disconnect = () => run('disconnect', async () => {
    if (!window.confirm('Ngắt kết nối website? Đơn cũ được giữ, EzChat Bán hàng gỡ webhook trên website.')) return;
    await api.post(`/marketplace-accounts/${account.value!.id}/disconnect`);
    await router.push('/marketplaces');
    return 'Đã ngắt kết nối website.';
});
const approve = (ids: string[] | null) => run(ids ? `approve-${ids[0]}` : 'approve-all', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/new-products/approve`, ids ? { listing_ids: ids } : { all: true });
    await load();
    return `Đã tạo ${formatNumber(data.data.created)} sản phẩm trong EzChat Bán hàng và liên kết với website.`;
});
const skip = (id: string) => run(`skip-${id}`, async () => {
    await api.post(`/marketplace-accounts/${account.value!.id}/new-products/skip`, { listing_ids: [id] });
    pending.value = pending.value.filter((p) => p.id !== id);
    overview.value.new_products = Math.max(0, overview.value.new_products - 1);
});

const decidePrice = (id: string, action: 'approve' | 'reject') => run(`price-${action}-${id}`, async () => {
    await api.post(`/price-changes/${id}/${action}`);
    prices.value = prices.value.filter((p) => p.id !== id);
    overview.value.pending_prices = prices.value.length;
    return action === 'approve' ? 'Đã áp dụng giá website vào EzChat Bán hàng.' : undefined;
});
const approveAllPrices = () => run('price-all', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/price-changes/approve-all`);
    prices.value = [];
    overview.value.pending_prices = 0;
    return `Đã áp dụng ${formatNumber(data.data.applied)} giá từ website.`;
});

const statusLabel = computed(() => (account.value?.status !== 'connected' ? 'Đã ngắt' : account.value.last_error ? 'Có lỗi' : 'Đang kết nối'));
const hookBad = computed(() => account.value?.webhook_health?.ok === false);
const hasSale = (p: NewListingData) => p.variants.length === 1 && p.variants[0].sale_price !== null && p.variants[0].regular_price !== null && p.variants[0].sale_price !== p.variants[0].regular_price;
</script>

<template>
    <div v-if="loading && !account" class="space-y-4"><Skeleton class="h-16" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="account">
        <ChannelHead
            :title="account.shop_name"
            marketplace="woocommerce"
            :crumbs="[{ label: 'Website', to: '/marketplaces?san=woocommerce' }]"
            :description="`Website WooCommerce · ${account.base_url ?? ''}${account.last_synced_at ? ` · lấy sản phẩm lần cuối ${formatDateTime(account.last_synced_at)}` : ''}`"
        >
            <template #chips>
                <span :class="cn('inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[12px] font-medium', account.status === 'connected' && !account.last_error ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600')">
                    <i :class="cn('size-1.5 rounded-full', account.status === 'connected' && !account.last_error ? 'bg-emerald-500' : 'bg-red-500')" />{{ statusLabel }}
                </span>
            </template>
            <template v-if="canEdit && account.status === 'connected'">
                <button type="button" :class="btn.outline" :disabled="busy === 'sync-orders'" data-testid="woo-sync-orders" @click="syncOrders"><ShoppingCart class="size-4" /> Đồng bộ đơn ngay</button>
                <button type="button" :class="btn.outline" :disabled="busy === 'sync'" @click="resync"><RefreshCw :class="cn('size-4', busy === 'sync' && 'animate-spin')" /> Lấy lại sản phẩm</button>
                <button type="button" :class="btn.outline" :disabled="busy === 'push'" @click="pushStock"><Send class="size-4" /> Đẩy tồn kho</button>
                <button type="button" :class="cn(btn.outline, 'border-red-200 text-red-600 hover:border-red-300 hover:text-red-700')" :disabled="busy === 'disconnect'" @click="disconnect"><Unplug class="size-4" /> Ngắt kết nối</button>
            </template>
        </ChannelHead>

        <p v-if="account.last_error" class="mb-5 flex items-center gap-2 rounded-[14px] border border-rose-200 bg-rose-50/70 px-5 py-3 text-sm"><AlertTriangle class="size-4 text-rose-500" /> {{ account.last_error }}</p>

        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="woo-overview">
            <ReportKpi label="Đơn tuần này" :icon="ShoppingCart" tone="violet" plain :value="overview.orders_this_week" unit="">Đơn từ website trong 7 ngày gần nhất</ReportKpi>
            <ReportKpi label="Sản phẩm đã liên kết" :icon="Link2" tone="blue" plain :value="null" :text="`${formatNumber(account.stats?.mapped ?? 0)} / ${formatNumber(account.stats?.listings ?? 0)}`" unit="">
                <template #chips><RouterLink :to="`/marketplaces/${account.id}/listings`"><ReportChip tone="blue">Liên kết sản phẩm →</ReportChip></RouterLink></template>
                {{ overview.new_products ? `${formatNumber(overview.new_products)} sản phẩm mới chờ duyệt` : 'Không có sản phẩm mới chờ duyệt' }}
            </ReportKpi>
            <ReportKpi label="Nhận đơn tức thì" :icon="Webhook" :tone="hookBad ? 'amber' : 'green'" plain :value="null" :text="!account.webhook_health ? 'Chưa kiểm tra' : account.webhook_health.ok ? 'Đang chạy' : 'Chậm'" unit="">
                {{ account.webhook_health ? `Kiểm tra lúc ${formatDateTime(account.webhook_health.checked_at)}` : 'Bấm Kiểm tra ngay để thử' }}
            </ReportKpi>
            <ReportKpi label="Lỗi đồng bộ" :icon="overview.sync_errors ? AlertTriangle : CircleCheck" :tone="overview.sync_errors ? 'red' : 'green'" plain :value="overview.sync_errors" unit="">
                <template v-if="overview.sync_errors" #chips><RouterLink :to="`/marketplaces/sync?tab=log&account=${account.id}`"><ReportChip tone="red">Xem lỗi →</ReportChip></RouterLink></template>
                {{ overview.sync_errors ? 'Đơn hoặc tồn chưa đồng bộ được' : 'Không có đơn hay tồn lỗi' }}
            </ReportKpi>
        </div>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
            <div class="min-w-0 space-y-5">
            <Card :class="hookBad ? '!border-amber-200 !bg-amber-50/50' : ''" title="Nhận đơn tức thì từ website" description="Woo tự tắt webhook sau nhiều lần gửi lỗi; EzChat Bán hàng kiểm tra mỗi giờ và bật lại." data-testid="woo-webhooks">
                <template v-if="canEdit && account.status === 'connected'" #actions>
                    <Button size="sm" variant="outline" :loading="busy === 'webhooks'" data-testid="woo-check-webhooks" @click="checkWebhooks"><Webhook /> Kiểm tra ngay</Button>
                </template>
                <div class="space-y-2 px-5 py-4 text-sm">
                    <p v-if="!account.webhook_health" class="text-muted-foreground">Chưa kiểm tra lần nào.</p>
                    <template v-else>
                        <p class="flex items-center gap-2">
                            <CircleCheck v-if="account.webhook_health.ok" class="size-4 text-emerald-600" /><AlertTriangle v-else class="size-4 text-rose-500" />
                            <span :class="account.webhook_health.ok ? 'text-emerald-700' : 'text-rose-600'">{{ account.webhook_health.ok ? 'Đang hoạt động' : 'Không kiểm tra được' }}</span>
                            <span class="text-xs text-muted-foreground">· {{ formatDateTime(account.webhook_health.checked_at) }}</span>
                        </p>
                        <p v-if="account.webhook_health.error" class="text-rose-600">{{ account.webhook_health.error }}</p>
                        <p v-if="account.webhook_health.reactivated.length" class="text-amber-700">Đã bật lại: {{ account.webhook_health.reactivated.map((t) => topicLabels[t] ?? t).join(', ') }}</p>
                        <p v-if="account.webhook_health.missing.length" class="text-amber-700">Đã tạo lại: {{ account.webhook_health.missing.map((t) => topicLabels[t] ?? t).join(', ') }}</p>
                    </template>
                    <p class="text-xs text-muted-foreground">Dù webhook lỗi, EzChat Bán hàng vẫn đối soát đơn theo chu kỳ ở trên nên không mất đơn.</p>
                </div>
                <div class="flex gap-2 border-t px-5 py-3 text-sm">
                    <RouterLink :to="`/marketplaces/sync?tab=log&account=${account.id}`" class="inline-flex items-center gap-1 text-primary hover:underline"><History class="size-4" /> Lịch sử và lỗi đồng bộ</RouterLink>
                </div>
            </Card>

            <Card title="Đồng bộ" data-testid="woo-settings">
                <div class="divide-y px-5 py-2">
                    <Switch
                        v-for="s in switches"
                        :key="s.key"
                        :label="s.label"
                        :hint="s.hint"
                        :disabled="!canEdit || busy === `set-${s.key}`"
                        :model-value="account.settings?.[s.key] ?? false"
                        :data-testid="`woo-switch-${s.key}`"
                        @update:model-value="(v: boolean) => setSwitch(s.key, v)"
                    />
                </div>
                <div class="grid gap-4 border-t px-5 py-4 sm:grid-cols-2">
                    <FormField label="Đối soát dự phòng mỗi" hint="Đơn mới về ngay tức thì qua webhook. Đây chỉ là lần kiểm tra lại định kỳ để bắt đơn bị sót khi webhook lỗi.">
                        <Select :model-value="String(account.order_sync_interval ?? 15)" :disabled="!canEdit" :options="intervals" data-testid="woo-interval" @update:model-value="setSyncInterval" />
                    </FormField>
                    <div class="text-sm">
                        <p class="mb-1.5 font-medium">Đồng bộ đơn từ</p>
                        <p class="text-muted-foreground" data-testid="woo-orders-since">{{ account.orders_since ? formatDateTime(account.orders_since) : '—' }}</p>
                        <p class="text-xs text-muted-foreground">Đơn tạo trước mốc này không vào EzChat Bán hàng.</p>
                    </div>
                </div>
                <div class="border-t px-5 py-4">
                    <FormField label="Kho trừ hàng cho đơn Woo">
                        <Select :model-value="account.warehouse?.id ?? ''" :disabled="!canEdit" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" @update:model-value="setWarehouse" />
                    </FormField>
                </div>
            </Card>

            </div>
            <div class="min-w-0 space-y-5">
            <Card title="Sản phẩm mới chờ duyệt" data-testid="woo-new-products">
                <template v-if="canEdit && pending.length" #actions>
                    <Button size="sm" :loading="busy === 'approve-all'" data-testid="woo-approve-all" @click="approve(null)">Duyệt tất cả</Button>
                </template>
                <EmptyState v-if="!account.settings?.import_new_products" :icon="PackagePlus" title="Đang tắt" description="Bật “Sản phẩm mới trên Woo” để xem sản phẩm chưa có trong EzChat Bán hàng." />
                <EmptyState v-else-if="!pending.length" :icon="PackagePlus" title="Không có sản phẩm mới" description="Mọi sản phẩm trên website đã có trong EzChat Bán hàng hoặc đã được bỏ qua." />
                <ul v-else class="max-h-[28rem] divide-y overflow-y-auto">
                    <li v-for="p in pending" :key="p.id" class="flex items-center gap-4 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ p.title }}</p>
                            <p class="truncate font-mono text-xs text-muted-foreground">
                                {{ p.variants.map((v) => v.seller_sku ?? 'chưa có SKU').join(', ') }}
                                <template v-if="p.variants.length > 1"> · {{ p.variants.length }} phiên bản</template>
                            </p>
                        </div>
                        <div class="text-right tabular-nums">
                            <p v-if="hasSale(p)" class="text-xs text-muted-foreground line-through">{{ formatMoney(p.variants[0].regular_price) }}</p>
                            <p :class="['font-semibold', hasSale(p) && 'text-rose-600']">{{ formatMoney(p.variants[0].price) }}</p>
                        </div>
                        <div v-if="canEdit" class="flex shrink-0 gap-1 text-sm">
                            <Button size="sm" variant="ghost" class="text-primary" :loading="busy === `approve-${p.id}`" @click="approve([p.id])">Duyệt</Button>
                            <Button size="sm" variant="ghost" :loading="busy === `skip-${p.id}`" @click="skip(p.id)">Bỏ qua</Button>
                        </div>
                    </li>
                </ul>
                <p class="border-t px-5 py-3 text-xs text-muted-foreground">Giá khuyến mãi (nếu có) được dùng làm giá bán khi tạo sản phẩm.</p>
            </Card>
            <Card id="status-map" title="Ánh xạ trạng thái" description="Trạng thái đơn trên website (kể cả trạng thái do plugin thêm) tương ứng trạng thái nào trong EzChat Bán hàng. Dòng có chữ Gợi ý được chọn sẵn theo tên, bấm Lưu để áp dụng." data-testid="woo-status-map">
                <template v-if="canEdit" #actions>
                    <Button size="sm" :loading="busy === 'status-map'" data-testid="woo-save-status-map" @click="saveMap"><ListChecks /> Lưu</Button>
                </template>
                <div v-if="!statusMap" class="p-5"><Skeleton class="h-24" /></div>
                <div v-else class="max-h-[26rem] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-5 py-2 font-medium">Trên website</th><th class="px-4 py-2 font-medium">Trong EzChat Bán hàng</th><th class="px-5 py-2 text-right font-medium">Đơn</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in mapRows" :key="r.raw" :data-testid="`status-row-${r.raw}`">
                                <td class="px-5 py-2">
                                    <div v-if="r.label" class="font-medium">{{ r.label }}</div>
                                    <div :class="r.label ? 'font-mono text-[11px] text-muted-foreground' : 'font-mono text-xs'">{{ r.raw }}</div>
                                </td>
                                <td class="px-4 py-1.5">
                                    <Select
                                        :model-value="mapDraft[r.raw] ?? ''"
                                        :disabled="!canEdit"
                                        :options="[{ value: '', label: r.fallback ? `Mặc định: ${orderStatus[r.fallback]?.label ?? r.fallback}` : 'Chưa ánh xạ' }, ...statusMap.statuses.map((x) => ({ value: x, label: orderStatus[x]?.label ?? x }))]"
                                        @update:model-value="(v?: string) => (mapDraft[r.raw] = v ?? '')"
                                    />
                                    <span v-if="r.suggested" class="mt-0.5 inline-block rounded-full bg-blue-50 px-1.5 text-[11px] text-blue-600" :data-testid="`status-suggested-${r.raw}`">Gợi ý theo tên, bấm Lưu để áp dụng</span>
                                </td>
                                <td class="px-5 py-2 text-right tabular-nums">
                                    {{ formatNumber(r.seen?.orders ?? 0) }}
                                    <span v-if="r.seen?.unknown" class="ml-1 rounded-full bg-amber-50 px-1.5 text-xs text-amber-700">{{ r.seen.unknown }} chưa ánh xạ</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form v-if="canEdit" class="flex gap-2 border-t p-4" @submit.prevent="addRaw">
                    <Input v-model="newRaw" placeholder="Thêm mã trạng thái của plugin, ví dụ dang-giao" class="font-mono" />
                    <Button type="submit" variant="outline">Thêm</Button>
                </form>
            </Card>
            </div>
        </div>

        <Card v-if="account.settings?.sync_prices || prices.length" title="Giá từ website chờ duyệt" description="Giá bằng 0 hoặc lệch quá 50% so với giá trong phần mềm thì không tự áp dụng." class="mt-5" data-testid="woo-pending-prices">
            <template v-if="canEdit && prices.length" #actions>
                <Button size="sm" :loading="busy === 'price-all'" data-testid="woo-prices-approve-all" @click="approveAllPrices">Áp dụng tất cả</Button>
            </template>
            <EmptyState v-if="!prices.length" :icon="Tag" title="Không có giá chờ duyệt" description="Giá website thay đổi hợp lý được áp dụng tự động; xem lịch sử ở trang từng sản phẩm." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Sản phẩm</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá EzChat Bán hàng</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá trên website</th>
                            <th class="px-4 py-2.5 text-right font-medium">Lệch</th>
                            <th class="px-4 py-2.5 font-medium">Lý do chờ</th>
                            <th class="px-5 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in prices" :key="p.id">
                            <td class="px-5 py-3">
                                <RouterLink v-if="p.product_id" :to="`/products/${p.product_id}`" class="font-medium hover:text-primary">{{ p.product_name }}</RouterLink>
                                <span v-else class="font-medium">{{ p.product_name ?? '—' }}</span>
                                <span v-if="p.variant_name" class="text-muted-foreground"> · {{ p.variant_name }}</span>
                                <p class="font-mono text-xs text-muted-foreground">{{ p.sku }}</p>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ p.ecso_price !== null ? formatMoney(p.ecso_price) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(p.website_price) }}<span v-if="p.on_sale" class="ml-1 text-xs font-normal text-muted-foreground">(KM)</span></td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="(p.diff_percent ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ p.diff_percent === null ? '—' : `${p.diff_percent > 0 ? '+' : ''}${p.diff_percent.toLocaleString('vi-VN')}%` }}
                            </td>
                            <td class="px-4 py-3">{{ p.reason }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                <template v-if="canEdit">
                                    <Button size="sm" variant="ghost" class="text-primary" :loading="busy === `price-approve-${p.id}`" @click="decidePrice(p.id, 'approve')">Áp dụng</Button>
                                    <Button size="sm" variant="ghost" :loading="busy === `price-reject-${p.id}`" @click="decidePrice(p.id, 'reject')">Bỏ qua</Button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>
</template>
