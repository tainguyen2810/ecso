<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlarmClock, CircleAlert, Clock, Download, FileUp, PackageCheck, Plug, Printer, Search, Truck, Undo2, Wallet } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import HeaderLink from '../../components/HeaderLink.vue';
import Tabs from '../../components/Tabs.vue';
import { marketplaceNames } from '../orders/labels';
import ShipmentDrawer from './ShipmentDrawer.vue';
import ShipmentImportDialog from './ShipmentImportDialog.vue';
import CarrierConnectDialog from './CarrierConnectDialog.vue';
import PushQueue from './PushQueue.vue';
import { carrierMarks, loadCarrierAccounts, openLabels, type CarrierAccountData } from './carriers';
import { carrierKeys, carrierText, shipmentTabs, shipmentTone, statusText, type ShipmentData, type ShipmentSummary } from './shipments';

/**
 * "Đẩy qua vận chuyển": orders waiting for a carrier (compare prices, push in bulk), then every shipment, what needs
 * the shop's attention, and the COD carriers hold.
 */
const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const toast = useToast();

const rows = ref<ShipmentData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const summary = ref<ShipmentSummary | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const tab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'to_push');
const q = ref('');
const carrier = ref('');
const channel = ref('');
const warehouse = ref('');
const cod = ref('');
const from = ref('');
const to = ref('');
const late = ref(route.query.late === '1');
const page = ref(1);
const openId = ref<string | null>(typeof route.query.shipment === 'string' ? route.query.shipment : null);
const warehouses = ref<{ id: string; name: string }[]>([]);

const queueCount = ref<number | null>(null);
const tabs = computed(() => [{ value: 'to_push', label: 'Chờ đẩy', count: queueCount.value }, ...shipmentTabs.map((t) => ({ ...t, count: summary.value?.tabs[t.value] ?? null }))]);

const accounts = ref<CarrierAccountData[]>([]);
const connectOpen = ref(false);
async function loadAccounts() {
    try {
        accounts.value = await loadCarrierAccounts();
    } catch {
        // The chips just stay empty.
    }
}
async function loadQueueCount() {
    try {
        queueCount.value = (await api.get('/carrier-shipments/queue')).data.meta.total;
    } catch {
        queueCount.value = null;
    }
}

/** Ticked shipments made through a carrier connection: their labels print together. */
const picked = ref<Set<string>>(new Set());
const printable = (s: ShipmentData) => s.source === 'api' && !!s.tracking_number && s.status !== 'cancelled';
function togglePick(id: string) {
    const next = new Set(picked.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    picked.value = next;
}
const printing = ref(false);
async function printPicked() {
    printing.value = true;
    try {
        await openLabels([...picked.value]);
    } catch (e) {
        toast.error((e as ApiError).field('ids') ?? (e as ApiError).message);
    } finally {
        printing.value = false;
    }
}

function filterParams() {
    return {
            tab: tab.value === 'all' || tab.value === 'to_push' ? undefined : tab.value,
            q: q.value || undefined,
            carrier: carrier.value || undefined,
            channel: channel.value || undefined,
            warehouse_id: warehouse.value || undefined,
            cod: cod.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        late: late.value ? 1 : undefined,
    };
}

async function load() {
    loading.value = true;
    loadError.value = null;
    picked.value = new Set();
    try {
        const params = { ...filterParams(), page: page.value };
        const [list, sum] = await Promise.all([api.get('/shipments', { params }), api.get('/shipments/summary')]);
        rows.value = list.data.data;
        meta.value = list.data.meta;
        summary.value = sum.data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    void load();
    void loadAccounts();
    void loadQueueCount();
    try {
        warehouses.value = (await api.get('/warehouses')).data.data;
    } catch {
        // The filter just stays empty.
    }
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([carrier, channel, warehouse, cod, from, to, late], () => ((page.value = 1), load()));
watch(tab, (t) => {
    router.replace({ query: { ...route.query, tab: t === 'to_push' ? undefined : t } });
    if (t !== 'to_push') ((page.value = 1), load());
});
function afterPush() {
    void load();
    void loadQueueCount();
}
watch(openId, (id) => router.replace({ query: { ...route.query, shipment: id ?? undefined } }));

function pickTab(value: string, onlyLate = false) {
    late.value = onlyLate;
    tab.value = value;
}

const importOpen = ref(false);
const exporting = ref(false);
async function exportXlsx() {
    exporting.value = true;
    try {
        const query = new URLSearchParams(Object.entries(filterParams()).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)]));
        await downloadFile(`/shipments/export?${query}`, 'van-don.xlsx');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        exporting.value = false;
    }
}

const codHeld = computed(() => (summary.value?.cod_held ?? []).reduce((s, r) => s + Number(r.amount), 0));
const codHeldBy = computed(() => (summary.value?.cod_held ?? []).map((r) => `${r.carrier ? carrierKeys[r.carrier] : r.carrier_name ?? 'Khác'} ${formatMoney(r.amount)}`).join(' · '));
const daysSince = (iso: string | null) => (iso ? Math.floor((Date.now() - new Date(iso).getTime()) / 86400000) : 0);
const isLate = (s: ShipmentData) => ['picked_up', 'in_transit', 'out_for_delivery', 'redelivering'].includes(s.status) && daysSince(s.picked_up_at) >= (summary.value?.late_days ?? 5);
</script>

<template>
    <PageHeader title="Đẩy qua vận chuyển" description="So sánh giá, đẩy đơn qua tài khoản hãng của shop, rồi theo dõi từng vận đơn và tiền COD hãng đang giữ.">
        <template #default>
            <div class="flex flex-wrap items-center gap-2" data-testid="carrier-chips">
                <button v-for="a in accounts" :key="a.id" class="inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-1 text-xs" :title="a.last_error ?? a.name" @click="connectOpen = true">
                    <span :class="cn('rounded px-1 text-[9px] font-bold text-white', carrierMarks[a.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[a.carrier]?.short ?? a.carrier }}</span>
                    {{ a.carrier_label }}<span :class="cn('size-2 rounded-full', a.status === 'connected' ? 'bg-emerald-500' : 'bg-red-500')" />
                </button>
                <Button size="sm" variant="outline" data-testid="open-carrier-connect" @click="connectOpen = true"><Plug /> Kết nối hãng</Button>
            </div>
        </template>
        <template #links>
            <HeaderLink :icon="Download" :disabled="exporting" data-testid="shipment-export" @click="exportXlsx">Xuất file</HeaderLink>
            <HeaderLink v-if="tenant.can('shipments.import')" :icon="FileUp" data-testid="shipment-import" @click="importOpen = true">Nhập mã vận đơn từ file</HeaderLink>
        </template>
    </PageHeader>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" data-testid="shipment-kpis">
        <button class="text-left" @click="pickTab('awaiting')"><KpiCard label="Chờ lấy hàng" :value="String(summary?.tabs.awaiting ?? 0)" :icon="Clock" :active="tab === 'awaiting' && !late">đã có mã, hãng chưa lấy</KpiCard></button>
        <button class="text-left" @click="pickTab('delivering')"><KpiCard label="Đang giao" :value="String(summary?.tabs.delivering ?? 0)" :icon="Truck" :active="tab === 'delivering' && !late">gồm đang vận chuyển, giao lại</KpiCard></button>
        <button class="text-left" @click="pickTab('failed')"><KpiCard label="Giao thất bại" :value="String(summary?.tabs.failed ?? 0)" :icon="CircleAlert" :tone="summary?.tabs.failed ? 'red' : 'slate'" :active="tab === 'failed'">cần xử lý: giao lại hoặc hoàn</KpiCard></button>
        <button class="text-left" @click="pickTab('returning')"><KpiCard label="Đang hoàn" :value="String(summary?.tabs.returning ?? 0)" :icon="Undo2" :active="tab === 'returning'">hàng đang về kho</KpiCard></button>
        <button class="text-left" data-testid="kpi-late" @click="pickTab('all', true)"><KpiCard label="Giao chậm" :value="String(summary?.late ?? 0)" :icon="AlarmClock" :tone="summary?.late ? 'amber' : 'slate'" :active="late">quá {{ summary?.late_days ?? 5 }} ngày chưa giao xong</KpiCard></button>
        <button class="text-left" @click="pickTab('delivered')"><KpiCard label="COD hãng đang giữ" :value="formatMoney(codHeld)" :icon="Wallet" tone="blue" :active="false"><span :title="codHeldBy">{{ codHeldBy || 'đã giao, chưa đối soát' }}</span></KpiCard></button>
    </div>

    <Card class="mt-5">
        <div class="border-b px-3"><Tabs v-model="tab" :tabs="tabs" variant="underline" @update:model-value="late = false" /></div>
        <PushQueue v-if="tab === 'to_push'" :accounts="accounts" @connect="connectOpen = true" @pushed="afterPush" />
        <template v-else>
        <div class="flex flex-wrap items-center gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tìm mã vận đơn, mã đơn" data-testid="shipment-search" />
            </div>
            <Select v-model="carrier" class="w-40" aria-label="Hãng" :options="[{ value: '', label: 'Mọi hãng' }, ...Object.entries(carrierKeys).map(([value, label]) => ({ value, label }))]" />
            <Select v-model="channel" class="w-36" aria-label="Kênh" :options="[{ value: '', label: 'Mọi kênh' }, { value: 'manual', label: 'Bán riêng' }, { value: 'woocommerce', label: 'Website' }, { value: 'shopee', label: 'Shopee' }, { value: 'tiktok', label: 'TikTok Shop' }, { value: 'lazada', label: 'Lazada' }, { value: 'tiki', label: 'Tiki' }]" />
            <Select v-model="warehouse" class="w-36" aria-label="Kho" :options="[{ value: '', label: 'Mọi kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
            <Select v-model="cod" class="w-40" aria-label="COD" :options="[{ value: '', label: 'COD: tất cả' }, { value: 'with', label: 'Có thu hộ' }, { value: 'without', label: 'Không thu hộ' }, { value: 'collected', label: 'Hãng đang giữ' }, { value: 'remitted', label: 'Đã đối soát' }]" />
            <label class="flex items-center gap-1 text-xs text-muted-foreground">Lấy hàng từ <Input v-model="from" type="date" class="h-9 w-36" /></label>
            <label class="flex items-center gap-1 text-xs text-muted-foreground">đến <Input v-model="to" type="date" class="h-9 w-36" /></label>
            <button :class="cn('rounded-full border px-2.5 py-1 text-xs', late ? 'border-amber-300 bg-amber-50 text-amber-700' : 'text-muted-foreground')" data-testid="filter-late" @click="late = !late">Giao chậm</button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="PackageCheck" title="Chưa có vận đơn" description="Vận đơn được tạo khi đơn có mã vận đơn hoặc được bàn giao cho hãng. Có thể tạo vận đơn từ trang chi tiết đơn." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="shipment-table">
                <thead class="whitespace-nowrap border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr>
                        <th class="w-8 py-3 pl-4"></th>
                        <th class="px-3 py-3">Mã vận đơn</th>
                        <th class="px-3 py-3">Đơn hàng</th>
                        <th class="px-3 py-3">Hãng · kho</th>
                        <th class="px-3 py-3">Người nhận</th>
                        <th class="px-3 py-3 text-right">COD</th>
                        <th class="px-3 py-3 text-right">Phí ship</th>
                        <th class="px-3 py-3">Trạng thái</th>
                        <th class="px-3 py-3">Lấy hàng</th>
                        <th class="px-4 py-3">Cập nhật</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in rows" :key="s.id" :class="cn('cursor-pointer hover:bg-blue-50/40', openId === s.id && 'bg-blue-50/60')" @click="openId = s.id">
                        <td class="py-3 pl-4" @click.stop>
                            <input v-if="printable(s)" type="checkbox" class="accent-blue-600" :checked="picked.has(s.id)" :aria-label="`Chọn ${s.tracking_number}`" @change="togglePick(s.id)" />
                        </td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <span v-if="s.tracking_number" class="font-mono font-medium text-primary">{{ s.tracking_number }}</span>
                            <span v-else class="text-xs text-muted-foreground">Không có mã</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3" @click.stop>
                            <RouterLink v-if="s.order" :to="`/orders/${s.order.id}`" class="inline-flex items-center gap-1.5 hover:underline">
                                <MarketplaceLogo :marketplace="s.order.marketplace" :name="marketplaceNames[s.order.marketplace]" size="sm" />{{ s.order.external_order_id }}
                            </RouterLink>
                            <div class="text-[11px] text-muted-foreground">{{ s.order?.channel ?? marketplaceNames[s.order?.marketplace ?? ''] }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3">{{ carrierText(s) }}<div v-if="s.warehouse" class="text-[11px] text-muted-foreground">{{ s.warehouse.name }}</div></td>
                        <td class="max-w-52 px-3 py-3">
                            <div class="truncate">{{ s.order?.recipient ?? '—' }}</div>
                            <div class="truncate text-[11px] text-muted-foreground">{{ s.order?.area ?? '' }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ Number(s.cod_amount) > 0 ? formatMoney(s.cod_amount) : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <template v-if="s.fee_actual">{{ formatMoney(s.fee_actual) }}<div class="text-[11px] text-emerald-600">hãng đã tính</div></template>
                            <template v-else-if="s.fee_estimated">{{ formatMoney(s.fee_estimated) }}<div class="text-[11px] text-muted-foreground">ước tính</div></template>
                            <span v-else class="text-muted-foreground">{{ s.source === 'marketplace' ? 'sàn trả' : '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <Badge :tone="shipmentTone[s.status]">{{ statusText(s) }}</Badge>
                            <Badge v-if="isLate(s)" tone="warning" class="ml-1">chậm {{ daysSince(s.picked_up_at) }} ngày</Badge>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ s.picked_up_at ? formatDateTime(s.picked_up_at) : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-muted-foreground">{{ s.last_event_at ? formatDateTime(s.last_event_at) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
        <div v-if="picked.size" class="sticky bottom-3 z-10 mx-4 mb-4 flex items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lg">
            <b>Đã chọn {{ picked.size }} vận đơn</b>
            <span class="ml-auto flex gap-2">
                <Button size="sm" variant="ghost" class="text-white hover:bg-white/10" @click="picked = new Set()">Bỏ chọn</Button>
                <Button size="sm" :disabled="printing" data-testid="print-labels" @click="printPicked"><Printer /> In nhãn của hãng</Button>
            </span>
        </div>
        </template>
    </Card>

    <ShipmentDrawer v-if="openId" :id="openId" :key="openId" @close="openId = null" @changed="load" />
    <ShipmentImportDialog v-model:open="importOpen" @saved="afterPush" />
    <CarrierConnectDialog v-model:open="connectOpen" :accounts="accounts" @changed="loadAccounts" />
</template>
