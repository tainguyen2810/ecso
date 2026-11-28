<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronRight, CircleAlert, ClockAlert, Download, FileUp, PackageCheck, PackageOpen, Plug, Printer, RefreshCw, Search, Send, Truck, Wallet } from 'lucide-vue-next';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import CarrierBox from '../shipping/CarrierBox.vue';
import ShipHead from '../shipping/ShipHead.vue';
import ShipPager from '../shipping/ShipPager.vue';
import { btn, card, pill, statusChip, th } from '../shipping/ui';
import { marketplaceNames } from '../orders/labels';
import ShipmentDrawer from './ShipmentDrawer.vue';
import ShipmentImportDialog from './ShipmentImportDialog.vue';
import CarrierConnectDialog from './CarrierConnectDialog.vue';
import PushQueue from './PushQueue.vue';
import { loadCarrierAccounts, openLabels, type CarrierAccountData } from './carriers';
import { carrierKeys, carrierText, shipmentTabs, statusText, type ShipmentData, type ShipmentSummary } from './shipments';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

/**
 * "Quản lý vận đơn": orders waiting for a carrier (compare prices, push in bulk), then every shipment, what needs
 * the shop's attention, and the COD carriers hold.
 */
const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const toast = useToast();

const rows = ref<ShipmentData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 30 });
const summary = ref<ShipmentSummary | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const tab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'to_push');
const q = ref('');
const carrier = ref('');
const channel = ref('');
const warehouse = ref('');
const cod = ref(typeof route.query.cod === 'string' ? route.query.cod : '');
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
const short = (v: number | string) => {
    const x = Number(v);
    return x >= 1_000_000 ? `${String(Math.round(x / 100_000) / 10).replace('.', ',')}tr` : formatNumber(x);
};
const codHeldBy = computed(() => (summary.value?.cod_held ?? []).slice(0, 3).map((r) => `${r.carrier ? carrierKeys[r.carrier] ?? r.carrier_name : r.carrier_name ?? 'Khác'} ${short(r.amount)}`).join(' · '));
const codHeldCount = computed(() => (summary.value?.cod_held ?? []).reduce((s, r) => s + r.count, 0));
const connectedAt = computed(() => accounts.value.map((a) => a.last_event_at).filter(Boolean).sort().pop() ?? null);
const ago = (iso: string) => {
    const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    return m < 60 ? `${m} phút trước` : m < 1440 ? `${Math.round(m / 60)} giờ trước` : formatDateTime(iso);
};
const daysSince = (iso: string | null) => (iso ? Math.floor((Date.now() - new Date(iso).getTime()) / 86400000) : 0);
const isLate = (s: ShipmentData) => ['picked_up', 'in_transit', 'out_for_delivery', 'redelivering'].includes(s.status) && daysSince(s.picked_up_at) >= (summary.value?.late_days ?? 5);
</script>

<template>
    <ShipHead title="Quản lý vận đơn" description="Mọi vận đơn của đơn web, tại quầy, Facebook, Zalo và đơn Shopee, TikTok: so giá, đẩy đơn qua tài khoản hãng của shop, theo dõi hành trình.">
        <button type="button" :class="btn.outline" :disabled="exporting" data-testid="shipment-export" @click="exportXlsx"><Download class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất file' }}</button>
        <button v-if="tenant.can('shipments.import')" type="button" :class="btn.outline" data-testid="shipment-import" @click="importOpen = true"><FileUp class="size-4" /> Nhập mã vận đơn từ file</button>
        <button v-if="queueCount" type="button" :class="btn.primary" data-testid="open-push-queue" @click="pickTab('to_push')"><Send class="size-4" /> Đẩy {{ formatNumber(queueCount) }} đơn chờ</button>
    </ShipHead>

    <div class="mb-5 flex flex-wrap items-center gap-2.5" data-testid="carrier-chips">
        <span class="mr-1 text-[13px] text-slate-500">{{ accounts.length ? 'Hãng đã kết nối' : 'Chưa kết nối hãng nào' }}</span>
        <button v-for="a in accounts" :key="a.id" type="button" class="flex h-11 items-center gap-2.5 rounded-[12px] border border-slate-200 bg-white pl-2 pr-3.5 hover:border-blue-300" :title="a.last_error ?? a.name" @click="connectOpen = true">
            <CarrierLogo :carrier="a.carrier" :name="a.carrier_label" :width="64" :height="22" />
            <span class="text-[14px] font-medium text-slate-800">{{ a.carrier_label }}</span>
            <span :class="cn('size-2 rounded-full', a.status === 'connected' ? 'bg-emerald-500' : 'bg-red-500')" />
        </button>
        <button type="button" class="flex h-11 items-center gap-2 rounded-[12px] border border-dashed border-slate-300 px-3.5 text-[14px] text-slate-600 hover:border-blue-400 hover:text-blue-700" data-testid="open-carrier-connect" @click="connectOpen = true"><Plug class="size-4" /> Kết nối hãng</button>
        <span v-if="connectedAt" class="ml-auto flex items-center gap-1.5 text-[13px] text-slate-500"><RefreshCw class="size-3.5" /> Hành trình cập nhật {{ ago(connectedAt) }}</span>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5" data-testid="shipment-kpis">
        <button type="button" class="text-left" @click="pickTab('to_push')">
            <ReportKpi label="Chờ đẩy" tip="Đơn đã xác nhận, chưa có vận đơn: chọn hãng và đẩy đơn ngay trên trang này." :icon="Send" tone="amber" :value="queueCount ?? 0" unit="đơn" :class="cn('h-full', tab === 'to_push' && 'ring-2 ring-blue-500/40')">
                <template #chips><ReportChip :tone="queueCount ? 'amber' : 'plain'">{{ queueCount ? 'Cần chọn hãng' : 'Không có đơn chờ' }}</ReportChip></template>
                Đơn đã xác nhận, chưa có vận đơn
            </ReportKpi>
        </button>
        <button type="button" class="text-left" @click="pickTab('awaiting')">
            <ReportKpi label="Chờ lấy hàng" tip="Đã có mã vận đơn, hãng chưa đến lấy hàng." :icon="PackageOpen" tone="violet" plain :value="summary?.tabs.awaiting ?? 0" unit="đơn" :class="cn('h-full', tab === 'awaiting' && 'ring-2 ring-blue-500/40')">
                <template #chips><ReportChip tone="plain">Hãng sắp đến lấy</ReportChip></template>
                Đã có mã, hãng chưa đến lấy
            </ReportKpi>
        </button>
        <button type="button" class="text-left" @click="pickTab('delivering')">
            <ReportKpi label="Đang giao" tip="Hãng đã lấy hàng, đang trên đường hoặc đang giao lại." :icon="Truck" tone="blue" plain :value="summary?.tabs.delivering ?? 0" unit="đơn" :class="cn('h-full', tab === 'delivering' && !late && 'ring-2 ring-blue-500/40')">
                <template #chips>
                    <ReportChip v-if="summary?.late" tone="amber">{{ formatNumber(summary.late) }} chậm</ReportChip>
                    <ReportChip v-else tone="up">Đúng tiến độ</ReportChip>
                </template>
                Gồm đang vận chuyển và giao lại
            </ReportKpi>
        </button>
        <button type="button" class="text-left" @click="pickTab('failed')">
            <ReportKpi label="Cần xử lý" tip="Đơn giao thất bại (gọi khách hoặc báo hãng giao lại) và đơn đang hoàn về kho." :icon="CircleAlert" tone="red" :value="(summary?.tabs.failed ?? 0) + (summary?.tabs.returning ?? 0)" unit="đơn" :class="cn('h-full', tab === 'failed' && 'ring-2 ring-blue-500/40')">
                <template #chips>
                    <ReportChip tone="red">{{ formatNumber(summary?.tabs.failed ?? 0) }} giao thất bại</ReportChip>
                    <ReportChip tone="amber">{{ formatNumber(summary?.tabs.returning ?? 0) }} đang hoàn</ReportChip>
                </template>
                Gọi khách hoặc báo hãng
            </ReportKpi>
        </button>
        <button type="button" class="text-left sm:col-span-2 lg:col-span-1" @click="cod = cod === 'collected' ? '' : 'collected'; pickTab('all')">
            <ReportKpi label="COD hãng đang giữ" tip="Tiền thu hộ của đơn đã giao mà hãng chưa chuyển về. Bấm để xem các đơn này." :icon="Wallet" primary :value="Math.round(codHeld)" class="h-full">
                <template #chips><ReportChip tone="onblue">{{ formatNumber(codHeldCount) }} đơn</ReportChip></template>
                {{ codHeldBy || 'Đã giao, chưa đối soát' }}
            </ReportKpi>
        </button>
    </div>

    <section :class="cn(card, 'mt-5 overflow-hidden')">
        <div class="overflow-x-auto border-b border-slate-100 px-5 py-3.5">
            <div class="inline-flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist" aria-label="Trạng thái vận đơn">
                <button v-for="t in tabs" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value" :class="pill(tab === t.value)" @click="pickTab(t.value)">
                    {{ t.label }}
                    <span v-if="t.count !== null" :class="cn('rounded-md px-1.5 text-[12px] font-semibold tabular-nums', tab === t.value ? 'bg-blue-600 text-white' : t.value === 'failed' && t.count ? 'bg-red-100 text-red-600' : 'bg-slate-200/70 text-slate-600')">{{ formatNumber(t.count) }}</span>
                </button>
            </div>
        </div>
        <PushQueue v-if="tab === 'to_push'" :accounts="accounts" @connect="connectOpen = true" @pushed="afterPush" />
        <template v-else>
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="relative min-w-60 flex-1">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="q" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" placeholder="Mã vận đơn, mã đơn" aria-label="Tìm vận đơn" data-testid="shipment-search" />
                </div>
                <Select v-model="carrier" class="w-full sm:w-44" aria-label="Hãng" :options="[{ value: '', label: 'Mọi hãng' }, ...Object.entries(carrierKeys).map(([value, label]) => ({ value, label }))]" />
                <Select v-model="channel" class="w-full sm:w-40" aria-label="Kênh" :options="[{ value: '', label: 'Mọi kênh' }, { value: 'manual', label: 'Bán riêng' }, { value: 'woocommerce', label: 'Website' }, { value: 'shopee', label: 'Shopee' }, { value: 'tiktok', label: 'TikTok Shop' }, { value: 'lazada', label: 'Lazada' }, { value: 'tiki', label: 'Tiki' }]" />
                <Select v-model="warehouse" class="w-full sm:w-40" aria-label="Kho" :options="[{ value: '', label: 'Mọi kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
                <Select v-model="cod" class="w-full sm:w-44" aria-label="COD" :options="[{ value: '', label: 'COD: tất cả' }, { value: 'with', label: 'Có thu hộ' }, { value: 'without', label: 'Không thu hộ' }, { value: 'collected', label: 'Hãng đang giữ' }, { value: 'remitted', label: 'Đã đối soát' }]" />
                <DateRangeInput v-model:from="from" v-model:to="to" clearable placeholder="Ngày lấy hàng" aria-label="Ngày lấy hàng" />
                <button type="button" :class="cn('flex h-11 items-center gap-2 whitespace-nowrap rounded-[10px] border px-3.5 text-[14px]', late ? 'border-amber-300 bg-amber-50 font-medium text-amber-700' : 'border-slate-200 text-slate-600 hover:border-amber-300')" data-testid="filter-late" @click="late = !late">
                    <ClockAlert class="size-4" /> Chỉ đơn chậm<template v-if="summary?.late"> ({{ formatNumber(summary.late) }})</template>
                </button>
            </div>

            <div v-if="picked.size" class="flex flex-wrap items-center gap-3 border-b border-blue-100 bg-blue-50/70 px-5 py-2.5 text-[14px]" data-testid="picked-bar">
                <span class="font-semibold text-blue-700">Đã chọn {{ picked.size }} vận đơn</span>
                <button type="button" :class="btn.smallPrimary" :disabled="printing" data-testid="print-labels" @click="printPicked"><Printer class="size-3.5" /> In phiếu giao hàng</button>
                <button type="button" class="ml-auto text-[13px] font-medium text-blue-600 hover:underline" @click="picked = new Set()">Bỏ chọn</button>
            </div>

            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="PackageCheck" title="Chưa có vận đơn" description="Vận đơn được tạo khi đơn có mã vận đơn hoặc được bàn giao cho hãng. Có thể tạo vận đơn từ trang chi tiết đơn." />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full min-w-[1180px]" data-testid="shipment-table">
                    <thead class="bg-slate-50/70 text-left">
                        <tr>
                            <th class="w-10 py-3 pl-5 pr-2"><span class="sr-only">Chọn</span></th>
                            <th :class="th">Mã vận đơn · đơn</th>
                            <th :class="th">Hãng · kho</th>
                            <th :class="th">Người nhận</th>
                            <th :class="cn(th, 'text-right')">COD</th>
                            <th :class="cn(th, 'text-right')">Phí ship</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="th">Hành trình mới nhất</th>
                            <th class="w-10 pr-5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="s in rows" :key="s.id" :class="cn('cursor-pointer text-[14px] hover:bg-slate-50', (openId === s.id || picked.has(s.id)) && 'bg-blue-50/50')" @click="openId = s.id">
                            <td class="py-3 pl-5 pr-2" @click.stop>
                                <input v-if="printable(s)" type="checkbox" class="size-[18px] accent-blue-600" :checked="picked.has(s.id)" :aria-label="`Chọn ${s.tracking_number}`" @change="togglePick(s.id)" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <p v-if="s.tracking_number" class="font-mono text-[13px] font-medium text-blue-600">{{ s.tracking_number }}</p>
                                <p v-else class="text-[12px] text-slate-400">Không có mã</p>
                                <RouterLink v-if="s.order" :to="`/orders/${s.order.id}`" class="mt-0.5 inline-flex items-center gap-1.5 text-[12px] text-slate-500 hover:text-blue-600 hover:underline" @click.stop>
                                    <MarketplaceLogo :marketplace="s.order.marketplace" :name="marketplaceNames[s.order.marketplace]" size="sm" />{{ orderCode(s.order) }}
                                </RouterLink>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <CarrierBox :carrier="s.carrier" :name="carrierText(s)" size="sm" />
                                    <div><p class="text-[13px] font-medium text-slate-800">{{ carrierText(s) }}</p><p v-if="s.warehouse" class="text-[12px] text-slate-400">{{ s.warehouse.name }}</p></div>
                                </div>
                            </td>
                            <td class="max-w-64 px-4 py-3">
                                <p class="truncate font-medium text-slate-900">{{ s.order?.recipient ?? '—' }}</p>
                                <p class="truncate text-[12px] text-slate-500">{{ [s.order?.phone, s.order?.area].filter(Boolean).join(' · ') || (s.order?.channel ?? '') }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ Number(s.cod_amount) > 0 ? formatMoney(s.cod_amount) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-[13px] tabular-nums">
                                <template v-if="s.fee_actual">{{ formatMoney(s.fee_actual) }}<p class="text-[11px] text-emerald-600">hãng đã tính</p></template>
                                <template v-else-if="s.fee_estimated">{{ formatMoney(s.fee_estimated) }}<p class="text-[11px] text-slate-400">ước tính</p></template>
                                <span v-else class="text-slate-400">{{ s.source === 'marketplace' ? 'Sàn trả' : '—' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span :class="statusChip(s.status)">{{ statusText(s) }}</span>
                                    <span v-if="isLate(s)" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700"><ClockAlert class="size-3" /> Chậm {{ daysSince(s.picked_up_at) }} ngày</span>
                                </div>
                            </td>
                            <td class="max-w-72 px-4 py-3">
                                <p class="truncate text-[13px] text-slate-700">{{ s.last_event?.description || s.last_event?.status_label || s.failure_reason || '—' }}</p>
                                <p class="text-[12px] text-slate-400">
                                    <template v-if="s.last_event_at">{{ formatDateTime(s.last_event_at) }}</template><template v-if="s.picked_up_at"> · lấy {{ formatDateTime(s.picked_up_at) }}</template>
                                </p>
                            </td>
                            <td class="py-3 pl-2 pr-5 text-right text-slate-300"><ChevronRight class="ml-auto size-4" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShipPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page || 30" unit="vận đơn" @go="(p) => ((page = p), load())" />
        </template>
    </section>

    <ShipmentDrawer v-if="openId" :id="openId" :key="openId" @close="openId = null" @changed="load" />
    <ShipmentImportDialog v-model:open="importOpen" @saved="afterPush" />
    <CarrierConnectDialog v-model:open="connectOpen" :accounts="accounts" @changed="loadAccounts" />
</template>
