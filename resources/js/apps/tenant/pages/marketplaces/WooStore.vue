<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ChevronLeft, Link2, PackagePlus, RefreshCw, Send, ShoppingCart, Unplug } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StatTile from '@/shared/ui/StatTile.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { MarketplaceAccountData, MarketplaceSyncSettings, NewListingData, Warehouse } from '@/shared/types';

/** Settings page of a connected WooCommerce store: sync switches, warehouse, new store products waiting for approval. */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();

const account = ref<MarketplaceAccountData | null>(null);
const overview = ref({ orders_this_week: 0, new_products: 0, sync_errors: 0 });
const pending = ref<NewListingData[]>([]);
const warehouses = ref<Warehouse[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const canEdit = computed(() => tenant.can('marketplace.connect'));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const id = route.params.id as string;
        const [a, p, w] = await Promise.all([api.get(`/marketplace-accounts/${id}`), api.get(`/marketplace-accounts/${id}/new-products`), api.get('/warehouses')]);
        account.value = a.data.data;
        overview.value = a.data.overview;
        pending.value = p.data.data;
        warehouses.value = w.data.data.filter((x: Warehouse) => x.status === 'active');
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

const switches: { key: keyof MarketplaceSyncSettings; label: string; hint: string }[] = [
    { key: 'sync_orders', label: 'Đơn hàng Woo về ECSO', hint: 'Vào Danh sách đơn hàng, nguồn WooCommerce, giữ hàng ngay' },
    { key: 'sync_stock', label: 'Tồn kho ECSO lên Woo', hint: 'Số có thể bán của kho bên dưới' },
    { key: 'sync_prices', label: 'Giá (đợt 2)', hint: 'Theo nguồn giá từng sản phẩm; giá khuyến mãi Woo là giá bán thực tế' },
    { key: 'import_new_products', label: 'Sản phẩm mới trên Woo', hint: 'Vào danh sách chờ duyệt' },
];

const setSwitch = (key: keyof MarketplaceSyncSettings, value: boolean) => run(`set-${key}`, async () => {
    const { data } = await api.patch(`/marketplace-accounts/${account.value!.id}`, { settings: { [key]: value } });
    account.value = { ...account.value!, settings: data.data.settings };
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
    if (!window.confirm('Ngắt kết nối website? Đơn cũ được giữ, ECSO gỡ webhook trên website.')) return;
    await api.post(`/marketplace-accounts/${account.value!.id}/disconnect`);
    await router.push('/marketplaces');
    return 'Đã ngắt kết nối website.';
});
const approve = (ids: string[] | null) => run(ids ? `approve-${ids[0]}` : 'approve-all', async () => {
    const { data } = await api.post(`/marketplace-accounts/${account.value!.id}/new-products/approve`, ids ? { listing_ids: ids } : { all: true });
    await load();
    return `Đã tạo ${formatNumber(data.data.created)} sản phẩm trong ECSO và liên kết với website.`;
});
const skip = (id: string) => run(`skip-${id}`, async () => {
    await api.post(`/marketplace-accounts/${account.value!.id}/new-products/skip`, { listing_ids: [id] });
    pending.value = pending.value.filter((p) => p.id !== id);
    overview.value.new_products = Math.max(0, overview.value.new_products - 1);
});

const statusLabel = computed(() => (account.value?.status !== 'connected' ? 'Đã ngắt' : account.value.last_error ? 'Có lỗi' : 'Đang kết nối'));
const hasSale = (p: NewListingData) => p.variants.length === 1 && p.variants[0].sale_price !== null && p.variants[0].regular_price !== null && p.variants[0].sale_price !== p.variants[0].regular_price;
</script>

<template>
    <RouterLink to="/marketplaces" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Kết nối sàn</RouterLink>

    <div v-if="loading && !account" class="space-y-4"><Skeleton class="h-16" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="account">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <MarketplaceLogo :marketplace="account.marketplace" :name="account.marketplace_name" size="lg" class="size-11" />
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">{{ account.shop_name }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ account.base_url }} ·
                        <span :class="account.status === 'connected' && !account.last_error ? 'text-success' : 'text-destructive'">{{ statusLabel }}</span>
                        <template v-if="account.last_synced_at"> · lấy sản phẩm lần cuối {{ formatDateTime(account.last_synced_at) }}</template>
                    </p>
                </div>
            </div>
            <div v-if="canEdit && account.status === 'connected'" class="flex flex-wrap gap-2">
                <Button variant="outline" :loading="busy === 'sync'" @click="resync"><RefreshCw /> Lấy lại sản phẩm</Button>
                <Button variant="outline" :loading="busy === 'push'" @click="pushStock"><Send /> Đẩy tồn kho</Button>
                <Button variant="outline" class="text-destructive" :loading="busy === 'disconnect'" @click="disconnect"><Unplug /> Ngắt kết nối</Button>
            </div>
        </div>

        <p v-if="account.last_error" class="mb-4 flex items-center gap-2 rounded-md border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm"><AlertTriangle class="size-4 text-rose-500" /> {{ account.last_error }}</p>

        <Card class="mb-4" data-testid="woo-overview">
            <div class="grid divide-y sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
                <StatTile :icon="Link2" label="Sản phẩm đã liên kết" :value="`${formatNumber(account.stats?.mapped ?? 0)} / ${formatNumber(account.stats?.listings ?? 0)}`" :to="`/marketplaces/${account.id}/listings`" />
                <StatTile :icon="PackagePlus" label="Chờ duyệt (SP mới)" :value="formatNumber(overview.new_products)" :tone="overview.new_products ? 'amber' : 'slate'" />
                <StatTile :icon="ShoppingCart" label="Đơn tuần này" :value="formatNumber(overview.orders_this_week)" tone="green" />
                <StatTile :icon="AlertTriangle" label="Lỗi đồng bộ" :value="formatNumber(overview.sync_errors)" :tone="overview.sync_errors ? 'red' : 'slate'" />
            </div>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card title="Đồng bộ" data-testid="woo-settings">
                <div class="divide-y px-5 py-2">
                    <Switch
                        v-for="s in switches"
                        :key="s.key"
                        :label="s.label"
                        :hint="s.hint"
                        :disabled="!canEdit || busy === `set-${s.key}` || s.key === 'sync_prices'"
                        :model-value="account.settings?.[s.key] ?? false"
                        :data-testid="`woo-switch-${s.key}`"
                        @update:model-value="(v: boolean) => setSwitch(s.key, v)"
                    />
                </div>
                <div class="border-t px-5 py-4">
                    <FormField label="Kho trừ hàng cho đơn Woo">
                        <Select :model-value="account.warehouse?.id ?? ''" :disabled="!canEdit" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" @update:model-value="setWarehouse" />
                    </FormField>
                </div>
            </Card>

            <Card title="Sản phẩm mới chờ duyệt" data-testid="woo-new-products">
                <template v-if="canEdit && pending.length" #actions>
                    <Button size="sm" :loading="busy === 'approve-all'" data-testid="woo-approve-all" @click="approve(null)">Duyệt tất cả</Button>
                </template>
                <EmptyState v-if="!account.settings?.import_new_products" :icon="PackagePlus" title="Đang tắt" description="Bật “Sản phẩm mới trên Woo” để xem sản phẩm chưa có trong ECSO." />
                <EmptyState v-else-if="!pending.length" :icon="PackagePlus" title="Không có sản phẩm mới" description="Mọi sản phẩm trên website đã có trong ECSO hoặc đã được bỏ qua." />
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
        </div>
    </template>
</template>
