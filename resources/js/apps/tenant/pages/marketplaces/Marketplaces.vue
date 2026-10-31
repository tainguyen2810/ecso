<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, FlaskConical, Link2, PlugZap, RefreshCw, Send, Unplug, Zap } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
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
import type { MarketplaceAccountData, MarketplaceCatalogEntry, OrderSummary, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();

const catalog = ref<MarketplaceCatalogEntry[]>([]);
const accounts = ref<MarketplaceAccountData[]>([]);
const webhooks = ref<{ id: number; marketplace: string; type: string; order: string | null; status: string; result: string | null; attempts: number; received_at: string; processed_at: string | null }[]>([]);
const warehouses = ref<Warehouse[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);

const connectOpen = ref(false);
const connectForm = ref({ shop_name: 'Shop giả lập', warehouse_id: '' });
const connectError = ref<ApiError | null>(null);

// Simulator (fake shops only)
const sim = ref<{ account: MarketplaceAccountData; failing: boolean; statuses: string[]; orders: OrderSummary[] } | null>(null);
const simForm = ref({ count: 3, status: 'fake_ready', max_items: 2, order: '', to: 'fake_packed' });

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
    warehouses.value = (await api.get('/warehouses')).data.data.filter((w: Warehouse) => w.status === 'active');
    connectForm.value.warehouse_id = warehouses.value.find((w) => w.is_default)?.id ?? '';
});

async function connectFake() {
    busy.value = 'connect';
    connectError.value = null;
    try {
        await api.post('/marketplace-accounts/fake', connectForm.value);
        toast.success('Đã kết nối shop giả lập và tải danh sách sản phẩm.');
        connectOpen.value = false;
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

const accountTone = (a: MarketplaceAccountData) => (a.status !== 'connected' ? 'muted' : a.circuit_open || a.last_error ? 'danger' : 'success');
const accountLabel = (a: MarketplaceAccountData) => (a.status !== 'connected' ? 'Đã ngắt' : a.circuit_open ? 'Tạm dừng do lỗi' : a.last_error ? 'Có lỗi' : 'Đang hoạt động');
</script>

<template>
    <PageHeader title="Kết nối sàn" description="Chỉ dùng API chính thức của sàn. Thao tác nào chưa được xác minh với tài liệu và credentials thật được đánh dấu UNKNOWN và không được giả lập.">
        <Button variant="outline" :loading="loading" @click="load"><RefreshCw /> Làm mới</Button>
    </PageHeader>

    <div v-if="loading && !catalog.length" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else class="space-y-4">
        <Card title="Gian hàng đã kết nối">
            <template v-if="tenant.can('marketplace.connect') && catalog.some((c) => c.is_fake)" #actions>
                <Button size="sm" data-testid="connect-fake" @click="connectOpen = true"><FlaskConical /> Kết nối shop giả lập</Button>
            </template>
            <EmptyState v-if="!accounts.length" :icon="PlugZap" title="Chưa kết nối gian hàng nào" description="Kết nối Shopee, TikTok Shop, Lazada, Tiki cần app credentials của sàn (chưa có). Trong lúc chờ, dùng shop giả lập để chạy thử toàn bộ quy trình đơn hàng và tồn kho." />
            <ul v-else class="divide-y" data-testid="account-list">
                <li v-for="a in accounts" :key="a.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <div class="min-w-56 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ a.shop_name }}</span>
                            <Badge :tone="accountTone(a)">{{ accountLabel(a) }}</Badge>
                            <Badge v-if="a.marketplace === 'fake'" tone="warning">Giả lập</Badge>
                        </div>
                        <div class="mt-0.5 text-xs text-muted-foreground">{{ a.marketplace_name }} · Shop ID {{ a.external_shop_id }} · kết nối {{ formatDateTime(a.connected_at) }}</div>
                        <div v-if="a.last_error" class="mt-1 flex items-center gap-1 text-xs text-destructive"><AlertTriangle class="size-3" /> {{ a.last_error }}</div>
                    </div>
                    <div v-if="a.stats" class="grid grid-cols-4 gap-4 text-center text-xs">
                        <div><div class="text-base font-semibold tabular-nums">{{ formatNumber(a.stats.listings) }}</div><div class="text-muted-foreground">Listing</div></div>
                        <div><div class="text-base font-semibold tabular-nums text-success">{{ formatNumber(a.stats.mapped) }}</div><div class="text-muted-foreground">Đã map</div></div>
                        <div><div class="text-base font-semibold tabular-nums">{{ formatNumber(a.stats.pending_push) }}</div><div class="text-muted-foreground">Chờ đẩy tồn</div></div>
                        <div><div :class="['text-base font-semibold tabular-nums', a.stats.push_errors && 'text-destructive']">{{ formatNumber(a.stats.push_errors) }}</div><div class="text-muted-foreground">Lỗi đẩy tồn</div></div>
                    </div>
                    <div v-if="a.status === 'connected'" class="flex flex-wrap items-center gap-2">
                        <Select class="w-44" :model-value="a.warehouse?.id ?? ''" :disabled="!tenant.can('marketplace.connect')" :options="warehouses.map((w) => ({ value: w.id, label: `Kho: ${w.code}` }))" aria-label="Kho xuất" @update:model-value="(v) => changeWarehouse(a, v)" />
                        <RouterLink :to="`/marketplaces/${a.id}/listings`"><Button size="sm" variant="outline"><Link2 /> Liên kết SKU <Badge v-if="a.stats?.suggested" tone="warning" class="ml-1">{{ a.stats.suggested }}</Badge></Button></RouterLink>
                        <template v-if="tenant.can('marketplace.connect')">
                            <Button size="sm" variant="outline" :loading="busy === `sync-${a.id}`" @click="syncProducts(a)"><RefreshCw /> Tải sản phẩm</Button>
                            <Button size="sm" variant="outline" :loading="busy === `push-${a.id}`" @click="pushStock(a)"><Send /> Đẩy tồn</Button>
                            <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" :checked="a.sync_enabled" @change="toggleSync(a)" /> Tự đồng bộ tồn</label>
                            <Button size="sm" variant="ghost" :loading="busy === `disc-${a.id}`" @click="disconnect(a)"><Unplug /> Ngắt</Button>
                        </template>
                    </div>
                </li>
            </ul>
        </Card>

        <Card v-if="sim" title="Bảng điều khiển sàn giả lập" :description="`Đóng vai sàn TMĐT cho shop “${sim.account.shop_name}”: tạo đơn, đổi trạng thái, gửi lại webhook, giả lập sàn lỗi. Mọi thay đổi đi qua đúng pipeline thật (webhook có chữ ký → hàng đợi → gọi API lấy đơn → xử lý tồn).`" data-testid="simulator">
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
                    <p class="text-xs text-muted-foreground">Bật để API sàn giả lập trả lỗi 503: webhook vẫn được nhận và lưu, job xử lý sẽ thử lại; đẩy tồn thử lại theo backoff, lỗi liên tiếp sẽ mở circuit breaker.</p>
                    <Button :variant="sim.failing ? 'destructive' : 'outline'" :loading="busy === 'sim-fail'" data-testid="sim-failure" @click="simFailure">{{ sim.failing ? 'Đang giả lập lỗi — Tắt' : 'Giả lập sàn lỗi' }}</Button>
                </div>
            </div>
        </Card>

        <Card title="Webhook gần đây" description="Webhook được xác thực chữ ký, lưu lại rồi trả 200 ngay; xử lý chạy ở hàng đợi. Webhook trùng được nhận diện và bỏ qua.">
            <template #actions><Button size="sm" variant="ghost" @click="refreshSim"><RefreshCw /></Button></template>
            <div v-if="!webhooks.length" class="px-5 py-6 text-sm text-muted-foreground">Chưa nhận webhook nào.</div>
            <div v-else class="max-h-80 overflow-y-auto">
                <table class="w-full text-sm" data-testid="webhook-table">
                    <thead class="sticky top-0 border-b bg-muted/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2 font-medium">Nhận lúc</th><th class="px-4 py-2 font-medium">Loại</th><th class="px-4 py-2 font-medium">Đơn</th><th class="px-4 py-2 font-medium">Trạng thái</th><th class="px-4 py-2 font-medium">Kết quả</th><th class="px-4 py-2 text-right font-medium">Lần thử</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="w in webhooks" :key="w.id">
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
        </Card>

        <Card title="Khả năng tích hợp theo sàn" description="Chỉ đánh dấu “Hỗ trợ” khi đã kiểm chứng với tài liệu chính thức và credentials. UNKNOWN = chưa có tài liệu/credentials để xác minh, hệ thống coi là không khả dụng.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="capability-matrix">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
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
        </Card>
    </div>

    <Dialog v-model:open="connectOpen" title="Kết nối shop giả lập" description="Shop giả lập chỉ dùng để chạy thử, được tắt hoàn toàn trên production (FAKE_MARKETPLACE_ENABLED=false). Danh sách sản phẩm được tạo từ SKU của bạn.">
        <div class="space-y-4">
            <FormField label="Tên shop" :error="connectError?.field('shop_name')"><Input v-model="connectForm.shop_name" /></FormField>
            <FormField label="Kho xuất hàng"><Select v-model="connectForm.warehouse_id" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" /></FormField>
            <p v-if="connectError && !connectError.field('shop_name')" class="text-sm text-destructive">{{ connectError.message }}</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="connectOpen = false">Hủy</Button>
            <Button :loading="busy === 'connect'" data-testid="connect-fake-submit" @click="connectFake">Kết nối</Button>
        </template>
    </Dialog>
</template>
