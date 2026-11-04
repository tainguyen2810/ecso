<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Ban, Banknote, Bookmark, ChevronDown, ChevronsRight, Clock, Columns3, Download, Filter, Plus, Printer, ReceiptText, RefreshCw, Search, ShoppingCart, UserPlus, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Tabs from '../../components/Tabs.vue';
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
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { actionLabels, deadlineLabel, marketplaceNames, orderFlags, orderStatus, orderTabs, paymentMethods, paymentStatus } from './labels';
import PrintDialog from './PrintDialog.vue';
import { downloadPackingSlips } from './packingSlip';
import OrderPipeline from './OrderPipeline.vue';
import OrderQuickView from './OrderQuickView.vue';
import type { MarketplaceAccountData, OrderDetail, OrderSummary, Ref, SalesChannel, Variant, Warehouse } from '@/shared/types';

const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const toast = useToast();

const orders = ref<OrderSummary[]>([]);
const counts = ref<Record<string, number>>({});
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const accounts = ref<MarketplaceAccountData[]>([]);
const channels = ref<SalesChannel[]>([]);
const warehouses = ref<Warehouse[]>([]);
const assignees = ref<Ref[]>([]);
const tags = ref<string[]>([]);
const pipeline = ref<InstanceType<typeof OrderPipeline> | null>(null);

const str = (key: string) => (route.query[key] as string) ?? '';
const filters = reactive({
    tab: str('tab') || 'all',
    q: str('q'),
    account: str('account'),
    source: str('source'),
    channel: str('channel'),
    warehouse: str('warehouse'),
    assignee: str('assignee'),
    status: str('status'),
    payment_status: str('payment_status'),
    from: str('from'),
    to: str('to'),
    customer: str('customer'),
    sku: str('sku'),
    tag: str('tag'),
    deliver_from: str('deliver_from'),
    deliver_to: str('deliver_to'),
    flags: route.query.flags ? String(route.query.flags).split(',') : ([] as string[]),
    sort: str('sort') || 'newest',
    page: Number(route.query.page ?? 1),
});
// Names of the picked customer / SKU, shown on their filter chips (the URL only keeps the ids).
const customerLabel = ref(str('customer_label'));
const skuLabel = ref(str('sku_label'));

const selected = ref<Set<string>>(new Set());
const allSelected = computed(() => orders.value.length > 0 && orders.value.every((o) => selected.value.has(o.id)));
const expanded = ref<Set<string>>(new Set());
const busy = ref<string | null>(null);
const assignOpen = ref(false);
const assignTo = ref('');
const bulkResult = ref<{ id: string; ok: boolean; message: string | null }[] | null>(null);
const cancelOpen = ref(false);
const cancelReason = ref('');
const payOpen = ref(false);
const payMethod = ref('cash');
const moreFilters = ref(false);

const tabs = computed(() => orderTabs.map((t) => ({ ...t, count: t.value === 'all' ? null : (counts.value[t.value] ?? 0) })));

/** Pipeline tile that matches the current filters (highlighted). */
const activeQueue = computed(() => {
    if (filters.flags.length === 1 && filters.flags[0] === 'payment_due' && filters.tab === 'all') return 'payment_due';
    if (filters.status === 'FAILED_DELIVERY') return 'failed_delivery';
    return ['to_confirm', 'to_pack', 'to_handover', 'shipping'].includes(filters.tab) && !filters.flags.length ? filters.tab : null;
});

const advancedCount = computed(() => [filters.status, filters.payment_status, filters.from || filters.to, filters.customer, filters.sku, filters.tag, filters.deliver_from || filters.deliver_to].filter(Boolean).length);

function query() {
    return {
        tab: filters.tab === 'all' ? undefined : filters.tab,
        q: filters.q || undefined,
        account: filters.account || undefined,
        source: filters.source || undefined,
        channel: filters.channel || undefined,
        warehouse: filters.warehouse || undefined,
        assignee: filters.assignee || undefined,
        status: filters.status || undefined,
        payment_status: filters.payment_status || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        customer: filters.customer || undefined,
        sku: filters.sku || undefined,
        tag: filters.tag || undefined,
        deliver_from: filters.deliver_from || undefined,
        deliver_to: filters.deliver_to || undefined,
        flags: filters.flags.length ? filters.flags : undefined,
        sort: filters.sort === 'newest' ? undefined : filters.sort,
        page: filters.page > 1 ? filters.page : undefined,
    };
}

async function load() {
    loading.value = true;
    loadError.value = null;
    router.replace({
        query: {
            ...query(),
            flags: filters.flags.length ? filters.flags.join(',') : undefined,
            customer_label: filters.customer ? customerLabel.value || undefined : undefined,
            sku_label: filters.sku ? skuLabel.value || undefined : undefined,
        },
    });
    try {
        const { data } = await api.get('/orders', { params: query() });
        orders.value = data.data;
        meta.value = data.meta;
        counts.value = data.meta.counts ?? {};
        selected.value = new Set([...selected.value].filter((id) => orders.value.some((o) => o.id === id)));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

function refresh() {
    pipeline.value?.load();
    return load();
}

onMounted(async () => {
    load();
    const [a, m, c, t, w] = await Promise.allSettled([api.get('/marketplace-accounts'), api.get('/orders/assignees'), api.get('/sales-channels'), api.get('/orders/tags'), api.get('/warehouses')]);
    if (w.status === 'fulfilled') warehouses.value = w.value.data.data;
    if (t.status === 'fulfilled') tags.value = t.value.data.data;
    if (a.status === 'fulfilled') accounts.value = a.value.data.data;
    if (c.status === 'fulfilled') channels.value = c.value.data.data;
    if (m.status === 'fulfilled') assignees.value = m.value.data.data;
});

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(
    () => [filters.tab, filters.account, filters.source, filters.channel, filters.warehouse, filters.assignee, filters.status, filters.payment_status, filters.from, filters.to, filters.customer, filters.sku, filters.tag, filters.deliver_from, filters.deliver_to, filters.sort, filters.flags.join()],
    () => ((filters.page = 1), (selected.value = new Set()), (expanded.value = new Set()), load()),
);

function toggleFlag(flag: string) {
    filters.flags = filters.flags.includes(flag) ? filters.flags.filter((f) => f !== flag) : [...filters.flags, flag];
}

function selectQueue(key: string) {
    filters.status = key === 'failed_delivery' ? 'FAILED_DELIVERY' : '';
    filters.flags = key === 'payment_due' ? ['payment_due'] : [];
    filters.tab = ['payment_due', 'failed_delivery'].includes(key) ? 'all' : key;
}

function clearAdvanced() {
    Object.assign(filters, { status: '', payment_status: '', from: '', to: '', customer: '', sku: '', tag: '', deliver_from: '', deliver_to: '' });
}

// Saved filters and visible columns, kept in this browser per business (same as the product list).
type ViewFilters = Omit<typeof filters, 'page'> & { customerLabel?: string; skuLabel?: string };
const storageKey = (name: string) => `ecso.orders.${name}.${tenant.current?.id ?? 'default'}`;
function readStore<T>(name: string, fallback: T): T {
    try {
        return JSON.parse(localStorage.getItem(storageKey(name)) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}
function writeStore(name: string, value: unknown) {
    try {
        localStorage.setItem(storageKey(name), JSON.stringify(value));
    } catch {
        // Private mode: keep for this visit only.
    }
}
const savedViews = ref<{ name: string; filters: ViewFilters }[]>(readStore('views', []));
function saveView() {
    const name = window.prompt('Tên bộ lọc', '')?.trim();
    if (!name) return;
    const { page: _page, ...current } = filters;
    const view = { ...current, flags: [...current.flags], customerLabel: customerLabel.value, skuLabel: skuLabel.value };
    savedViews.value = [...savedViews.value.filter((v) => v.name !== name), { name, filters: view }];
    writeStore('views', savedViews.value);
    toast.success(`Đã lưu bộ lọc "${name}"`);
}
function applyView(view: { filters: ViewFilters }) {
    const { customerLabel: c, skuLabel: s, ...rest } = view.filters;
    customerLabel.value = c ?? '';
    skuLabel.value = s ?? '';
    Object.assign(filters, { ...rest, flags: [...(rest.flags ?? [])] });
    moreFilters.value = advancedCount.value > 0;
}
function removeView(name: string) {
    savedViews.value = savedViews.value.filter((v) => v.name !== name);
    writeStore('views', savedViews.value);
}
const hasFilters = computed(() => filters.tab !== 'all' || !!filters.q || !!filters.source || !!filters.channel || !!filters.warehouse || !!filters.account || !!filters.assignee || filters.flags.length > 0 || advancedCount.value > 0);

const columnLabels = { date: 'Ngày tạo đơn', customer: 'Tên khách hàng', products: 'Sản phẩm', status: 'Trạng thái đơn hàng', payment: 'Thanh toán', deadline: 'Hạn giao / hẹn giao', assignee: 'Người xử lý', total: 'Khách phải trả' } as const;
type ColumnKey = keyof typeof columnLabels;
// Like Sapo's list: one line per order; products and assignee can be turned on from "Cột".
const columns = ref<Record<ColumnKey, boolean>>({ date: true, customer: true, products: false, status: true, payment: true, deadline: true, assignee: false, total: true, ...readStore('columns', {}) });
watch(columns, (v) => writeStore('columns', v), { deep: true });
const columnsOpen = ref(false);
const columnCount = computed(() => 3 + Object.values(columns.value).filter(Boolean).length);

// Customer filter: a small search box over /customers.
const customerQuery = ref('');
const customerResults = ref<{ id: string; name: string; code: string; phone: string | null }[]>([]);
let customerTimer: ReturnType<typeof setTimeout> | undefined;
watch(customerQuery, (q) => {
    clearTimeout(customerTimer);
    if (!q.trim()) return void (customerResults.value = []);
    customerTimer = setTimeout(async () => {
        const { data } = await api.get('/customers', { params: { q, per_page: 6 } });
        customerResults.value = data.data;
    }, 250);
});
function pickCustomer(c: { id: string; name: string }) {
    customerLabel.value = c.name;
    filters.customer = c.id;
    customerQuery.value = '';
    customerResults.value = [];
}
function pickSku(v: Variant) {
    skuLabel.value = v.sku;
    filters.sku = v.id!;
}

function toggle(id: string) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(orders.value.map((o) => o.id));
}
function toggleExpand(id: string) {
    const next = new Set(expanded.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expanded.value = next;
}

async function bulk(action: string, extra: Record<string, unknown> = {}) {
    busy.value = action;
    try {
        const { data } = await api.post('/orders/bulk', { ids: [...selected.value], action, ...extra });
        const results = data.data as { id: string; ok: boolean; message: string | null }[];
        const failed = results.filter((r) => !r.ok);
        if (failed.length) bulkResult.value = results;
        else toast.success(`Đã xử lý ${results.length} đơn.`);
        selected.value = new Set();
        await refresh();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function assignSelected() {
    await bulk('assign', { user_id: assignTo.value || null });
    assignOpen.value = false;
}
async function cancelSelected() {
    await bulk('cancel', { reason: cancelReason.value || null });
    cancelOpen.value = false;
    cancelReason.value = '';
}
async function paySelected() {
    await bulk('pay', { method: payMethod.value });
    payOpen.value = false;
}

const selectedOrders = computed(() => orders.value.filter((o) => selected.value.has(o.id)));
const selectedDue = computed(() => selectedOrders.value.filter((o) => o.source === 'manual' && Number(o.balance_due ?? 0) > 0));

async function print() {
    busy.value = 'print';
    try {
        const { data } = await api.post('/orders/packing-slips', { ids: [...selected.value] });
        await downloadPackingSlips(data.data);
        toast.success(`Đã tạo phiếu đóng gói cho ${data.data.length} đơn.`);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file PDF.');
    } finally {
        busy.value = null;
    }
}

const printOpen = ref(false);
const printOrders = ref<OrderDetail[]>([]);
/** Sales receipts of the selected manual orders, printed in one job (template picked in the print dialog). */
async function printInvoices() {
    const manual = selectedOrders.value.filter((o) => o.source === 'manual');
    if (!manual.length) return void toast.info('Hóa đơn bán hàng chỉ in cho đơn của kênh bán riêng.');
    busy.value = 'invoice';
    try {
        printOrders.value = await Promise.all(manual.map((o) => api.get(`/orders/${o.id}`).then(({ data }) => data.data as OrderDetail)));
        printOpen.value = true;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function exportOrders() {
    busy.value = 'export';
    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries({ ...query(), page: undefined, tab: filters.tab === 'all' ? undefined : filters.tab })) {
            if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, v));
            else if (value !== undefined) params.append(key, String(value));
        }
        await downloadFile(`/orders/export?${params.toString()}`, 'don-hang.xlsx');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không xuất được file.');
    } finally {
        busy.value = null;
    }
}

const orderLabel = (id: string) => orders.value.find((o) => o.id === id)?.external_order_id ?? id;
</script>

<template>
    <PageHeader title="Đơn hàng" description="Đơn từ các sàn (cập nhật theo webhook, thao tác gửi lên sàn qua API) và đơn của kênh bán riêng (xử lý ngay trên ECSO).">
        <Button variant="outline" :loading="loading" @click="refresh"><RefreshCw /> Làm mới</Button>
        <Button variant="outline" :loading="busy === 'export'" data-testid="export-orders" @click="exportOrders"><Download /> Xuất Excel</Button>
        <Button v-if="tenant.can('orders.create')" data-testid="create-order" @click="$router.push('/orders/new')"><Plus /> Tạo đơn</Button>
    </PageHeader>

    <OrderPipeline ref="pipeline" :active="activeQueue" @select="selectQueue" />

    <Card>
        <div class="border-b px-3"><Tabs v-model="filters.tab" :tabs="tabs" variant="underline" data-testid="order-tabs" /></div>
        <div class="space-y-3 border-b p-4">
            <!-- Row 1: search and tools; row 2: the list filters in equal columns (balanced like Sapo). -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-72 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="filters.q" class="pl-8" placeholder="Tìm theo mã đơn, mã vận đơn, SKU, tên sản phẩm, tên hoặc SĐT khách…" data-testid="order-search" />
                </div>
                <Button variant="outline" :class="cn(moreFilters && 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-50')" data-testid="more-filters" @click="moreFilters = !moreFilters">
                    <Filter /> Bộ lọc khác <span v-if="advancedCount" class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{{ advancedCount }}</span>
                </Button>
                <div class="relative">
                    <Button variant="outline" data-testid="columns-button" @click="columnsOpen = !columnsOpen"><Columns3 /> Cột</Button>
                    <div v-if="columnsOpen" class="absolute right-0 top-11 z-20 w-56 rounded-md border bg-card p-2 text-sm shadow-lg" data-testid="columns-menu">
                        <label v-for="(label, key) in columnLabels" :key="key" class="flex items-center gap-2 rounded px-2 py-1.5 hover:bg-muted">
                            <input v-model="columns[key]" type="checkbox" /> {{ label }}
                        </label>
                    </div>
                </div>
                <Button variant="outline" :disabled="!hasFilters" data-testid="save-view" @click="saveView"><Bookmark /> Lưu bộ lọc</Button>
            </div>
            <div :class="cn('grid grid-cols-2 gap-2 sm:grid-cols-3', warehouses.length > 1 ? 'lg:grid-cols-6' : 'lg:grid-cols-5')">
                <Select v-model="filters.source" :options="[{ value: '', label: 'Mọi nguồn đơn' }, { value: 'manual', label: 'Kênh bán riêng' }, { value: 'marketplace', label: 'Sàn TMĐT' }]" aria-label="Nguồn đơn" data-testid="filter-source" />
                <Select v-model="filters.channel" :disabled="filters.source === 'marketplace' || !channels.length" :options="[{ value: '', label: 'Mọi kênh riêng' }, ...channels.map((c) => ({ value: c.id, label: c.name }))]" aria-label="Kênh bán" />
                <Select v-if="warehouses.length > 1" v-model="filters.warehouse" :options="[{ value: '', label: 'Mọi kho xuất' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" aria-label="Kho xuất" data-testid="filter-warehouse" />
                <Select v-model="filters.account" :disabled="filters.source === 'manual' || !accounts.length" :options="[{ value: '', label: 'Tất cả gian hàng' }, ...accounts.map((a) => ({ value: a.id, label: a.shop_name }))]" aria-label="Gian hàng" />
                <Select v-model="filters.assignee" :options="[{ value: '', label: 'Mọi người xử lý' }, { value: 'me', label: 'Đơn của tôi' }, { value: 'none', label: 'Chưa phân công' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]" aria-label="Người xử lý" />
                <Select v-model="filters.sort" :options="[{ value: 'newest', label: 'Sắp xếp: Mới nhất' }, { value: 'oldest', label: 'Sắp xếp: Cũ nhất' }, { value: 'ship_by', label: 'Sắp xếp: Hạn giao gần nhất' }]" aria-label="Sắp xếp" />
            </div>

            <div v-if="moreFilters" class="grid gap-3 rounded-lg border bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="advanced-filters">
                <FormField label="Ngày đặt từ" for="f-from"><Input id="f-from" v-model="filters.from" type="date" /></FormField>
                <FormField label="đến ngày" for="f-to"><Input id="f-to" v-model="filters.to" type="date" /></FormField>
                <FormField label="Trạng thái thanh toán" for="f-pay">
                    <Select id="f-pay" v-model="filters.payment_status" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(paymentStatus).map(([value, s]) => ({ value, label: s.label }))]" />
                </FormField>
                <FormField label="Trạng thái đơn" for="f-status">
                    <Select id="f-status" v-model="filters.status" :options="[{ value: '', label: 'Theo tab đang chọn' }, ...Object.entries(orderStatus).map(([value, s]) => ({ value, label: s.label }))]" />
                </FormField>
                <div class="sm:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium">Khách hàng</span>
                    <div class="relative">
                        <Input v-model="customerQuery" placeholder="Tìm theo tên, mã, số điện thoại…" data-testid="filter-customer" />
                        <ul v-if="customerResults.length" class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border bg-card text-sm shadow-md">
                            <li v-for="c in customerResults" :key="c.id">
                                <button type="button" class="flex w-full justify-between px-3 py-2 text-left hover:bg-muted" @click="pickCustomer(c)">
                                    <span>{{ c.name }}</span><span class="text-xs text-muted-foreground">{{ c.phone ?? c.code }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium">Có sản phẩm</span>
                    <SkuPicker placeholder="Tìm SKU, tên sản phẩm hoặc quét mã vạch…" @pick="pickSku" />
                </div>
                <FormField label="Tag" for="f-tag">
                    <Select id="f-tag" v-model="filters.tag" :options="[{ value: '', label: tags.length ? 'Mọi tag' : 'Chưa có tag nào' }, ...tags.map((t) => ({ value: t, label: t }))]" data-testid="filter-tag" />
                </FormField>
                <FormField label="Hẹn giao từ" for="f-deliver-from"><Input id="f-deliver-from" v-model="filters.deliver_from" type="date" /></FormField>
                <FormField label="đến ngày" for="f-deliver-to"><Input id="f-deliver-to" v-model="filters.deliver_to" type="date" /></FormField>
            </div>

            <div v-if="savedViews.length" class="flex flex-wrap items-center gap-2 text-xs" data-testid="saved-views">
                <span class="text-muted-foreground">Bộ lọc đã lưu:</span>
                <span v-for="v in savedViews" :key="v.name" class="inline-flex items-center gap-1 rounded-full border bg-card pl-2.5 pr-1">
                    <button type="button" class="py-0.5 hover:text-primary" @click="applyView(v)">{{ v.name }}</button>
                    <button type="button" class="rounded-full p-0.5 text-muted-foreground hover:bg-muted" :aria-label="`Xóa bộ lọc ${v.name}`" @click="removeView(v.name)"><X class="size-3" /></button>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <span class="mr-1 text-xs text-muted-foreground">Lọc nhanh:</span>
                <button
                    v-for="f in [...orderFlags, { value: 'payment_due', label: 'Còn phải thu' }]"
                    :key="f.value"
                    type="button"
                    :class="cn('rounded-full border px-2.5 py-1 text-xs', filters.flags.includes(f.value) ? 'border-primary/30 bg-blue-50 font-medium text-blue-700' : 'bg-card text-muted-foreground hover:bg-muted')"
                    :data-testid="`flag-${f.value}`"
                    @click="toggleFlag(f.value)"
                >
                    {{ f.label }}
                </button>
                <template v-if="advancedCount">
                    <span class="mx-1 h-4 w-px bg-border" />
                    <span v-if="filters.from || filters.to" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ filters.from ? formatDate(filters.from) : '…' }} – {{ filters.to ? formatDate(filters.to) : '…' }}<button type="button" aria-label="Bỏ lọc ngày" @click="(filters.from = ''), (filters.to = '')"><X class="size-3" /></button></span>
                    <span v-if="filters.payment_status" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ paymentStatus[filters.payment_status]?.label }}<button type="button" aria-label="Bỏ lọc thanh toán" @click="filters.payment_status = ''"><X class="size-3" /></button></span>
                    <span v-if="filters.status" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ orderStatus[filters.status]?.label }}<button type="button" aria-label="Bỏ lọc trạng thái" @click="filters.status = ''"><X class="size-3" /></button></span>
                    <span v-if="filters.customer" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">Khách: {{ customerLabel || '…' }}<button type="button" aria-label="Bỏ lọc khách" @click="filters.customer = ''"><X class="size-3" /></button></span>
                    <span v-if="filters.sku" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">SKU: {{ skuLabel || '…' }}<button type="button" aria-label="Bỏ lọc sản phẩm" @click="filters.sku = ''"><X class="size-3" /></button></span>
                    <span v-if="filters.tag" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">Tag: {{ filters.tag }}<button type="button" aria-label="Bỏ lọc tag" @click="filters.tag = ''"><X class="size-3" /></button></span>
                    <span v-if="filters.deliver_from || filters.deliver_to" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">Hẹn giao {{ filters.deliver_from ? formatDate(filters.deliver_from) : '…' }} – {{ filters.deliver_to ? formatDate(filters.deliver_to) : '…' }}<button type="button" aria-label="Bỏ lọc ngày hẹn giao" @click="(filters.deliver_from = ''), (filters.deliver_to = '')"><X class="size-3" /></button></span>
                    <button type="button" class="text-xs text-muted-foreground hover:underline" @click="clearAdvanced">Xóa bộ lọc</button>
                </template>
            </div>
        </div>

        <div v-if="selected.size" class="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b border-blue-100 bg-blue-50 px-4 py-2.5 text-sm" data-testid="bulk-bar">
            <span class="mr-1 rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">{{ selected.size }}</span><span class="mr-2 font-medium">đơn đã chọn</span>
            <template v-if="tenant.can('orders.update')">
                <Button size="sm" variant="outline" :loading="busy === 'confirm'" @click="bulk('confirm')">{{ actionLabels.confirm }}</Button>
                <Button size="sm" variant="outline" :loading="busy === 'pack'" @click="bulk('pack')">{{ actionLabels.pack }}</Button>
                <Button size="sm" variant="outline" :loading="busy === 'handover'" @click="bulk('handover')">{{ actionLabels.handover }}</Button>
            </template>
            <Button v-if="tenant.can('orders.payment')" size="sm" variant="outline" :disabled="!selectedDue.length" data-testid="bulk-pay" @click="payOpen = true"><Banknote /> Thanh toán nhanh</Button>
            <Button v-if="tenant.can('orders.assign')" size="sm" variant="outline" @click="assignOpen = true"><UserPlus /> Phân công</Button>
            <Button size="sm" variant="outline" :loading="busy === 'invoice'" data-testid="bulk-invoice" @click="printInvoices"><ReceiptText /> In hóa đơn</Button>
            <Button v-if="tenant.can('orders.print')" size="sm" :loading="busy === 'print'" data-testid="print-slips" @click="print"><Printer /> In phiếu đóng gói</Button>
            <Button v-if="tenant.can('orders.cancel')" size="sm" variant="outline" class="text-destructive" data-testid="bulk-cancel" @click="cancelOpen = true"><Ban /> Hủy đơn</Button>
            <button class="ml-auto text-xs text-muted-foreground hover:underline" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !orders.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-14" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!orders.length"
            :icon="ShoppingCart"
            title="Không có đơn nào"
            :description="accounts.length ? 'Không có đơn khớp bộ lọc. Đơn mới từ sàn sẽ xuất hiện ở đây trong vài giây sau khi sàn gửi webhook.' : 'Tạo đơn bán ở kênh riêng, hoặc kết nối gian hàng để nhận đơn từ sàn.'"
        >
            <RouterLink v-if="!accounts.length" to="/marketplaces"><Button>Kết nối gian hàng</Button></RouterLink>
        </EmptyState>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="order-table">
                <thead class="border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr>
                        <th class="w-8 py-3 pl-3" />
                        <th class="w-8 py-3"><input type="checkbox" :checked="allSelected" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="w-[22%] px-3 py-3">Mã đơn hàng</th>
                        <th v-if="columns.date" class="w-36 whitespace-nowrap px-3 py-3">Ngày tạo đơn</th>
                        <th v-if="columns.customer" class="px-3 py-3">Tên khách hàng</th>
                        <th v-if="columns.products" class="px-3 py-3">Sản phẩm</th>
                        <th v-if="columns.status" class="w-40 whitespace-nowrap px-3 py-3">Trạng thái đơn hàng</th>
                        <th v-if="columns.payment" class="w-44 px-3 py-3">Thanh toán</th>
                        <th v-if="columns.deadline" class="w-36 px-3 py-3">Hạn giao</th>
                        <th v-if="columns.assignee" class="px-3 py-3">Xử lý</th>
                        <th v-if="columns.total" class="w-36 whitespace-nowrap px-4 py-3 text-right">Khách phải trả</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template v-for="o in orders" :key="o.id">
                        <tr :class="cn('cursor-pointer hover:bg-blue-50/40', selected.has(o.id) && 'bg-blue-50/70 hover:bg-blue-50/70', expanded.has(o.id) && 'bg-slate-50')" @click="$router.push(`/orders/${o.id}`)">
                            <td class="py-3 pl-3" @click.stop>
                                <button type="button" class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" :aria-label="expanded.has(o.id) ? `Thu gọn đơn ${o.external_order_id}` : `Xem nhanh đơn ${o.external_order_id}`" :aria-expanded="expanded.has(o.id)" data-testid="quick-view-toggle" @click="toggleExpand(o.id)">
                                    <ChevronDown v-if="expanded.has(o.id)" class="size-4" /><ChevronsRight v-else class="size-4" />
                                </button>
                            </td>
                            <td class="py-3" @click.stop><input type="checkbox" :checked="selected.has(o.id)" :aria-label="`Chọn đơn ${o.external_order_id}`" @change="toggle(o.id)" /></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" size="sm" />
                                    <span class="font-medium text-primary">{{ o.external_order_id }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1 pl-7 text-[11px] text-muted-foreground">
                                    {{ o.shop?.name ?? o.channel?.name ?? marketplaceNames[o.marketplace] }}
                                    <span v-if="o.flags.shortage" class="rounded-full bg-rose-50 px-1.5 text-rose-600" title="Thiếu hàng">Thiếu hàng</span>
                                    <span v-if="o.flags.unmapped" class="rounded-full bg-amber-50 px-1.5 text-amber-700">Chưa map SKU</span>
                                    <span v-if="o.sync_warning" class="rounded-full bg-amber-50 px-1.5 text-amber-700">Cảnh báo</span>
                                    <span v-if="o.flags.printed" class="inline-flex items-center gap-0.5"><Printer class="size-3" /> Đã in</span>
                                    <span v-for="t in o.tags ?? []" :key="t" class="rounded-full bg-slate-100 px-1.5 text-slate-600">#{{ t }}</span>
                                </div>
                            </td>
                            <td v-if="columns.date" class="whitespace-nowrap px-3 py-3 tabular-nums">{{ formatDateTime(o.placed_at) }}</td>
                            <td v-if="columns.customer" class="max-w-56 px-3 py-3">
                                <div class="truncate">{{ o.customer?.name ?? o.buyer_name ?? 'Khách lẻ' }}</div>
                            </td>
                            <td v-if="columns.products" class="max-w-80 px-3 py-3">
                                <div v-for="(i, idx) in o.items_preview" :key="idx" class="truncate text-xs">
                                    <span class="font-medium tabular-nums">{{ i.quantity }}×</span> {{ i.name }}<span v-if="i.variant_name" class="text-muted-foreground"> · {{ i.variant_name }}</span>
                                </div>
                                <div v-if="(o.items_count ?? 0) > 3" class="text-xs text-muted-foreground">+{{ (o.items_count ?? 0) - 3 }} sản phẩm khác</div>
                            </td>
                            <td v-if="columns.status" class="px-3 py-3"><Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label ?? o.status }}</Badge></td>
                            <td v-if="columns.payment" class="whitespace-nowrap px-3 py-3 text-xs">
                                <span v-if="o.payment_status && o.status !== 'CANCELLED'" class="inline-flex items-center gap-1.5" :title="paymentStatus[o.payment_status]?.label">
                                    <span :class="cn('size-2.5 rounded-full border-2', o.payment_status === 'paid' ? 'border-emerald-500 bg-emerald-500' : o.payment_status === 'partial' ? 'border-amber-500 bg-[linear-gradient(90deg,var(--color-amber-500)_50%,transparent_50%)]' : 'border-slate-400')" />
                                    <span :class="o.payment_status === 'paid' ? 'text-emerald-600' : o.payment_status === 'partial' ? 'text-amber-700' : 'text-muted-foreground'">{{ paymentStatus[o.payment_status]?.label }}</span>
                                </span>
                                <span v-else-if="o.is_cod" class="text-muted-foreground">COD</span>
                                <span v-else class="text-muted-foreground">{{ o.source === 'manual' ? '—' : 'Sàn thu' }}</span>
                            </td>
                            <td v-if="columns.deadline" class="whitespace-nowrap px-3 py-3 text-xs">
                                <span v-if="o.flags.overdue" class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-medium text-rose-600"><Clock class="size-3" />{{ deadlineLabel(o.ship_by_at) }}</span>
                                <span v-else-if="o.flags.due_soon" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700"><Clock class="size-3" />{{ deadlineLabel(o.ship_by_at) }}</span>
                                <span v-else class="text-muted-foreground">{{ o.ship_by_at ? formatDateTime(o.ship_by_at) : '—' }}</span>
                                <div v-if="o.deliver_on" class="mt-0.5 text-foreground" data-testid="row-deliver-on">Hẹn {{ formatDate(o.deliver_on) }}</div>
                            </td>
                            <td v-if="columns.assignee" class="px-3 py-3 text-xs text-muted-foreground">{{ o.assignee?.name ?? '—' }}</td>
                            <td v-if="columns.total" class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(o.total) }}</td>
                        </tr>
                        <tr v-if="expanded.has(o.id)">
                            <td :colspan="columnCount" class="p-0"><OrderQuickView :id="o.id" /></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>

    <PrintDialog v-model:open="printOpen" :orders="printOrders" />

    <Dialog v-model:open="assignOpen" title="Phân công đơn" :description="`${selected.size} đơn được chọn`">
        <Select v-model="assignTo" :options="[{ value: '', label: '— Bỏ phân công —' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]" aria-label="Nhân viên" />
        <template #footer>
            <Button variant="outline" @click="assignOpen = false">Hủy</Button>
            <Button :loading="busy === 'assign'" @click="assignSelected">Lưu</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="cancelOpen" title="Hủy các đơn đã chọn" :description="`${selected.size} đơn. Đơn kênh riêng nhả hàng giữ ngay; đơn sàn gửi yêu cầu hủy lên sàn. Đơn đã xuất kho không hủy được.`">
        <FormField label="Lý do hủy" for="bulk-cancel-reason"><Input id="bulk-cancel-reason" v-model="cancelReason" placeholder="Hết hàng, khách yêu cầu…" /></FormField>
        <template #footer>
            <Button variant="outline" @click="cancelOpen = false">Đóng</Button>
            <Button variant="destructive" :loading="busy === 'cancel'" data-testid="confirm-bulk-cancel" @click="cancelSelected">Hủy {{ selected.size }} đơn</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="payOpen" title="Thanh toán nhanh" :description="`Ghi nhận khách đã trả đủ cho ${selectedDue.length} đơn kênh riêng còn phải thu. Đơn sàn và đơn đã thu đủ được bỏ qua.`">
        <p class="mb-3 text-sm">Tổng thu: <b class="tabular-nums">{{ formatMoney(selectedDue.reduce((s, o) => s + Number(o.balance_due ?? 0), 0)) }}</b></p>
        <FormField label="Hình thức" for="bulk-pay-method">
            <Select id="bulk-pay-method" v-model="payMethod" :options="Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))" />
        </FormField>
        <template #footer>
            <Button variant="outline" @click="payOpen = false">Đóng</Button>
            <Button :loading="busy === 'pay'" data-testid="confirm-bulk-pay" @click="paySelected">Ghi nhận</Button>
        </template>
    </Dialog>

    <Dialog :open="bulkResult !== null" title="Kết quả xử lý hàng loạt" size="lg" @update:open="(v) => !v && (bulkResult = null)">
        <p class="mb-3 text-sm text-muted-foreground">
            {{ bulkResult?.filter((r) => r.ok).length }} đơn thành công, {{ bulkResult?.filter((r) => !r.ok).length }} đơn không xử lý được. Mỗi đơn được xử lý riêng; lỗi của đơn này không ảnh hưởng đơn khác.
        </p>
        <ul class="divide-y rounded-md border text-sm" data-testid="bulk-result">
            <li v-for="r in bulkResult?.filter((x) => !x.ok)" :key="r.id" class="flex items-start justify-between gap-3 px-3 py-2">
                <span class="font-mono text-xs">{{ orderLabel(r.id) }}</span>
                <span class="text-right text-xs text-destructive">{{ r.message }}</span>
            </li>
        </ul>
        <template #footer><Button @click="bulkResult = null">Đóng</Button></template>
    </Dialog>
</template>
