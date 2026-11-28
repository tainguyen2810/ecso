<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import DateTimeInput from '@/shared/ui/DateTimeInput.vue';
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Ban, Cake, CalendarClock, ChevronLeft, Clock, Crown, Download, HandCoins, Link2, Mail, MapPin, Merge, Pencil, Phone, Plus, Receipt, ShoppingBag, Trash2, TriangleAlert } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ChannelDots from './ChannelDots.vue';
import { avatarTone, btnOutline, btnPrimary, card } from './frame';
import Tabs from '../../components/Tabs.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import { emptyAddress, type AddressParts } from '@/shared/lib/geo';
import CustomerDialog from './CustomerDialog.vue';
import CustomerLoyaltyCard from './CustomerLoyaltyCard.vue';
import DebtEntryDialog from './DebtEntryDialog.vue';
import DebtReceiptDialog from './DebtReceiptDialog.vue';
import MergeDialog from './MergeDialog.vue';
import Pager from './Pager.vue';
import { usePaged } from './usePaged';
import { customerSources, customerStatus, customerTypes, genderLabels, noteKinds, priceTierLabels } from './labels';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber, initials } from '@/shared/lib/utils';
import { initials as shortInitials } from './frame';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { marketplaceNames, orderStatus, paymentMethodNames, paymentMethods, paymentStatus, returnStatus, returnTypes } from '../orders/labels';
import type { Customer, CustomerActivity, CustomerAddress, CustomerLedgerEntry, CustomerNote, CustomerSummary, CustomerTask, OrderPaymentData, OrderReturnData, OrderSummary } from '@/shared/types';

type Detail = Customer & { orders: OrderSummary[]; payments: OrderPaymentData[]; addresses: CustomerAddress[]; notes: CustomerNote[]; summary?: CustomerSummary };

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('customers.manage'));
// Cards on this page take the report style (14px corners, plain titles), like the other Khách hàng pages.
provide('soft-cards', true);
const customer = ref<Detail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const dialog = ref(false);
const tab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'overview');
watch(tab, (t) => router.replace({ query: t === 'overview' ? {} : { tab: t } }));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/customers/${route.params.id}`);
        customer.value = data.data;
        openTab(tab.value);
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function remove() {
    if (!window.confirm(`Xóa khách ${customer.value!.name}?`)) return;
    try {
        await api.delete(`/customers/${customer.value!.id}`);
        toast.success('Đã xóa khách hàng.');
        await router.push('/customers');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Debt book, loaded when its tab is opened.
const ledger = ref<CustomerLedgerEntry[] | null>(null);
const ledgerError = ref<string | null>(null);
async function loadLedger() {
    ledgerError.value = null;
    try {
        ledger.value = (await api.get(`/customers/${route.params.id}/ledger`)).data.data;
    } catch (e) {
        ledgerError.value = (e as ApiError).message;
    }
}

// Server-paged tabs, loaded the first time they are opened.
const base = () => `/customers/${route.params.id}`;
const ordersPage = usePaged<OrderSummary>(() => `${base()}/orders`);
const paymentsPage = usePaged<OrderPaymentData>(() => `${base()}/payments`);
const returnsPage = usePaged<OrderReturnData>(() => `${base()}/returns`);
const activityPage = usePaged<CustomerActivity>(() => `${base()}/activity`);
function openTab(t: string) {
    if (t === 'debt' && ledger.value === null) loadLedger();
    if (t === 'orders') ordersPage.ensure();
    if (t === 'payments') paymentsPage.ensure();
    if (t === 'returns') returnsPage.ensure();
    if (t === 'notes') activityPage.ensure();
}
watch(tab, openTab);

// Debt documents: receipts, opening balance, adjustments.
const canCollect = computed(() => tenant.can('orders.payment'));
const canAdjust = computed(() => tenant.can('customers.debt_adjust'));
const receiptDialog = ref(false);
const entryDialog = ref(false);
const entryKind = ref<'opening' | 'adjustment'>('adjustment');
const hasOpening = computed(() => (ledger.value ?? []).some((l) => l.type === 'opening'));
function newEntry(kind: 'opening' | 'adjustment') {
    entryKind.value = kind;
    entryDialog.value = true;
}
async function debtChanged() {
    ledger.value = null;
    paymentsPage.loaded.value = false;
    activityPage.loaded.value = false;
    await load();
}
async function voidEntry(l: CustomerLedgerEntry) {
    const reason = window.prompt(`Hủy chứng từ ${l.ref}? Nhập lý do:`);
    if (!reason?.trim()) return;
    try {
        await api.post(`${base()}/debt-entries/${l.entry_id}/void`, { reason: reason.trim() });
        toast.success('Đã hủy chứng từ.');
        await debtChanged();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
const exporting = ref(false);
async function exportLedger() {
    exporting.value = true;
    try {
        await downloadFile(`${base()}/ledger/export`, `cong-no-${customer.value!.code}.xlsx`);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        exporting.value = false;
    }
}
const creditUse = computed(() => {
    const limit = Number(customer.value?.summary?.credit_limit ?? 0);
    return limit > 0 ? Math.min(100, Math.round((Number(customer.value!.debt ?? 0) / limit) * 100)) : null;
});

// Follow-ups on this customer.
const tasks = ref<CustomerTask[]>([]);
const taskForm = reactive({ title: '', due_at: '' });
const taskSaving = ref(false);
async function loadTasks() {
    try {
        tasks.value = (await api.get(`/customers/${route.params.id}/tasks`)).data.data;
    } catch {
        tasks.value = [];
    }
}
watch(tab, (t) => t === 'notes' && loadTasks(), { immediate: true });
async function addTask() {
    taskSaving.value = true;
    try {
        await api.post(`${base()}/tasks`, { title: taskForm.title.trim(), due_at: taskForm.due_at.replace('T', ' ') });
        Object.assign(taskForm, { title: '', due_at: '' });
        activityPage.loaded.value = false;
        await Promise.all([loadTasks(), activityPage.load()]);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        taskSaving.value = false;
    }
}
async function doneTask(t: CustomerTask) {
    try {
        await api.post(`${base()}/tasks/${t.id}/done`);
        await Promise.all([loadTasks(), activityPage.load()]);
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
async function removeTask(t: CustomerTask) {
    if (!window.confirm(`Xóa việc "${t.title}"?`)) return;
    try {
        await api.delete(`${base()}/tasks/${t.id}`);
        await loadTasks();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Merge another card into this one: pick it, then review the merge.
const canMerge = computed(() => tenant.can('customers.merge'));
const pickOpen = ref(false);
const pickQuery = ref('');
const pickResults = ref<Customer[]>([]);
const mergeOpen = ref(false);
const mergeSource = ref('');
let pickTimer: ReturnType<typeof setTimeout> | undefined;
watch(pickQuery, (q) => {
    clearTimeout(pickTimer);
    if (!q.trim()) return void (pickResults.value = []);
    pickTimer = setTimeout(async () => {
        const { data } = await api.get('/customers', { params: { q, per_page: 8 } });
        pickResults.value = (data.data as Customer[]).filter((c) => c.id !== customer.value?.id);
    }, 250);
});
function pickForMerge(c: Customer) {
    mergeSource.value = c.id;
    pickOpen.value = false;
    pickQuery.value = '';
    mergeOpen.value = true;
}
async function merged() {
    ledger.value = null;
    for (const p of [ordersPage, paymentsPage, returnsPage, activityPage]) p.loaded.value = false;
    await Promise.all([load(), loadLinks()]);
}

// Channel ids and past merges.
const links = ref<{ identities: { source: string; external_id: string; label: string | null; last_seen_at: string | null }[]; merges: { id: string; code: string; name: string; by: string | null; at: string | null }[] } | null>(null);
async function loadLinks() {
    try {
        links.value = (await api.get(`/customers/${route.params.id}/links`)).data.data;
    } catch {
        links.value = null;
    }
}
onMounted(loadLinks);

// Activity log wording.
const activityLabels: Record<string, string> = {
    'customer.created': 'Tạo khách hàng',
    'customer.updated': 'Sửa thông tin',
    'customer.bulk_updated': 'Cập nhật hàng loạt',
    'customer.debt_opening': 'Ghi công nợ đầu kỳ',
    'customer.debt_adjustment': 'Điều chỉnh công nợ',
    'customer.debt_voided': 'Hủy chứng từ công nợ',
    'customer.debt_collected': 'Thu nợ',
    'customer.imported': 'Nhập từ file',
    'customer.merged': 'Gộp khách',
    'customer.task_created': 'Hẹn việc',
    'customer.task_done': 'Xong việc',
    'customer.auto_grouped': 'Tự xếp nhóm',
};
const fieldLabels: Record<string, string> = {
    name: 'tên', phone: 'số điện thoại', email: 'email', address: 'địa chỉ', customer_group_id: 'nhóm', group: 'nhóm', status: 'trạng thái',
    birthday: 'ngày sinh', gender: 'giới tính', tax_code: 'mã số thuế', tags: 'tags', note: 'ghi chú', assigned_user_id: 'phụ trách',
    payment_terms_days: 'hạn thanh toán', credit_limit: 'hạn mức nợ',
};
function activityDetail(a: CustomerActivity): string {
    const c = (a.changes ?? {}) as Record<string, unknown>;
    if (c.fields && typeof c.fields === 'object') {
        return Object.entries(c.fields as Record<string, unknown>)
            .map(([k, v]) => {
                const name = fieldLabels[k] ?? k;
                const diff = v as { from?: unknown; to?: unknown } | string;
                if (typeof diff === 'object' && diff && 'to' in diff) return `${name}: ${diff.from ?? '—'} → ${diff.to ?? '—'}`;
                return `đổi ${name}`;
            })
            .join('; ');
    }
    const parts: string[] = [];
    if (c.number) parts.push(String(c.number));
    if (c.amount !== undefined) parts.push(formatMoney(String(c.amount)));
    if (c.voucher) parts.push(`phiếu ${c.voucher}`);
    if (c.merged) parts.push(`gộp ${c.merged} vào khách này`);
    if (c.title) parts.push(String(c.title));
    if (c.group) parts.push(`nhóm ${c.group}`);
    if (c.reason) parts.push(String(c.reason));
    return parts.join(' · ');
}

// Extra delivery addresses.
const addressDialog = ref(false);
const editingAddress = ref<CustomerAddress | null>(null);
const addressForm = reactive({ label: '', recipient: '', phone: '', address: '' });
const addressParts = ref<AddressParts>(emptyAddress());
const addressLegacy = ref<string | null>(null);
const addressError = ref<ApiError | null>(null);
const addressSaving = ref(false);
function editAddress(a: CustomerAddress | null) {
    editingAddress.value = a;
    addressError.value = null;
    Object.assign(addressForm, { label: a?.label ?? '', recipient: a?.recipient ?? '', phone: a?.phone ?? '', address: a?.address ?? '' });
    addressParts.value = a?.province_code ? { street: a.street ?? '', province_code: a.province_code, ward_code: a.ward_code ?? '' } : emptyAddress();
    addressLegacy.value = a && !a.province_code ? a.address : null;
    addressDialog.value = true;
}
async function saveAddress() {
    addressSaving.value = true;
    addressError.value = null;
    const body: Record<string, unknown> = Object.fromEntries(Object.entries(addressForm).map(([k, v]) => [k, v === '' ? null : v]));
    if (addressParts.value.province_code) {
        delete body.address;
        Object.assign(body, { street: addressParts.value.street || null, province_code: addressParts.value.province_code, ward_code: addressParts.value.ward_code || null });
    }
    const base = `/customers/${customer.value!.id}/addresses`;
    try {
        const { data } = editingAddress.value ? await api.put(`${base}/${editingAddress.value.id}`, body) : await api.post(base, body);
        const list = customer.value!.addresses;
        const i = list.findIndex((a) => a.id === data.data.id);
        i >= 0 ? list.splice(i, 1, data.data) : list.push(data.data);
        addressDialog.value = false;
    } catch (e) {
        addressError.value = e as ApiError;
    } finally {
        addressSaving.value = false;
    }
}
async function removeAddress(a: CustomerAddress) {
    if (!window.confirm('Xóa địa chỉ này?')) return;
    try {
        await api.delete(`/customers/${customer.value!.id}/addresses/${a.id}`);
        customer.value!.addresses = customer.value!.addresses.filter((x) => x.id !== a.id);
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Note timeline.
const noteBody = ref('');
const noteKind = ref('note');
const noteSaving = ref(false);
async function addNote() {
    if (!noteBody.value.trim()) return;
    noteSaving.value = true;
    try {
        const { data } = await api.post(`/customers/${customer.value!.id}/notes`, { body: noteBody.value.trim(), kind: noteKind.value });
        customer.value!.notes.unshift(data.data);
        noteBody.value = '';
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        noteSaving.value = false;
    }
}
async function removeNote(n: CustomerNote) {
    if (!window.confirm('Xóa ghi chú này?')) return;
    try {
        await api.delete(`/customers/${customer.value!.id}/notes/${n.id}`);
        customer.value!.notes = customer.value!.notes.filter((x) => x.id !== n.id);
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

/** "Hôm nay", "Hôm qua", "5 ngày trước" for the last purchase. */
const lastOrderLabel = computed(() => {
    const d = customer.value?.summary?.days_since_last_order;
    if (d == null) return customer.value?.last_order_at ? formatDate(customer.value.last_order_at) : '—';
    return d <= 0 ? 'Hôm nay' : d === 1 ? 'Hôm qua' : `${formatNumber(d)} ngày trước`;
});
/** The last two parts of the address (district, province) for the header line. */
const shortAddress = computed(() => (customer.value?.address ?? '').split(',').map((x) => x.trim()).filter(Boolean).slice(-2).join(', '));
const tabs = computed(() => [
    { value: 'overview', label: 'Tổng quan' },
    { value: 'orders', label: 'Đơn hàng', count: customer.value?.orders_count ?? null },
    { value: 'debt', label: 'Công nợ' },
    { value: 'payments', label: 'Thanh toán' },
    { value: 'returns', label: 'Trả hàng' },
    { value: 'addresses', label: 'Địa chỉ', count: (customer.value?.addresses.length ?? 0) + (customer.value?.address ? 1 : 0) },
    { value: 'notes', label: 'Ghi chú và hoạt động', count: customer.value?.notes.length ?? null },
]);
const th = 'px-5 py-3 font-semibold';
const thead = 'border-b border-slate-100 bg-slate-50/60 text-left text-[12px] uppercase tracking-wider text-slate-400';
/** "14/12 · còn 16 ngày" for the birthday in the header. */
const birthdayLabel = computed(() => {
    const b = customer.value?.birthday;
    if (!b) return '';
    const [, m, d] = b.slice(0, 10).split('-').map(Number);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    let next = new Date(now.getFullYear(), m! - 1, d!);
    if (next < today) next = new Date(now.getFullYear() + 1, m! - 1, d!);
    const days = Math.round((next.getTime() - today.getTime()) / 86_400_000);
    return `${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')} · ${days === 0 ? 'hôm nay' : `còn ${days} ngày`}`;
});
/** Habit tiles of the overview. */
const habits = computed(() => {
    const sm = customer.value?.summary;
    return [
        { label: 'Đơn đầu tiên', value: sm?.first_order_at ? formatDate(sm.first_order_at) : '—' },
        { label: 'Chu kỳ mua', value: sm?.purchase_interval_days != null ? `${formatNumber(sm.purchase_interval_days)} ngày/lần` : '—' },
        { label: 'Chưa mua lại', value: sm?.days_since_last_order != null ? `${formatNumber(sm.days_since_last_order)} ngày` : '—' },
        { label: 'Giá trị hàng trả', value: formatMoney(sm?.returned_value ?? 0) },
    ];
});
const topMax = computed(() => Math.max(1, ...(customer.value?.summary?.top_products ?? []).map((p) => Number(p.amount))));
</script>

<template>
    <div v-if="loading && !customer" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="customer">
        <RouterLink to="/customers" class="mb-4 inline-flex items-center gap-1.5 text-[14px] text-slate-500 hover:text-blue-700" aria-label="Khách hàng"><ChevronLeft class="size-4" /> Danh sách khách hàng</RouterLink>
        <!-- Header: who the customer is at a glance, the main actions on the right. -->
        <div :class="cn(card, 'mb-5 flex flex-wrap items-center gap-5 p-6')">
            <span :class="cn('grid size-[72px] shrink-0 place-items-center rounded-full text-[24px] font-bold', avatarTone(customer.name))">{{ shortInitials(customer.name) }}</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="truncate text-[26px] font-bold tracking-tight text-slate-900">{{ customer.name }}</h1>
                    <span v-if="customer.tier" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[12px] font-semibold text-amber-700 ring-1 ring-amber-200" :style="customer.tier.color ? { color: customer.tier.color, background: `${customer.tier.color}14`, '--tw-ring-color': `${customer.tier.color}40` } : undefined"><Crown class="size-3" />{{ customer.tier.name }}</span>
                    <RouterLink v-if="customer.group" :to="{ path: '/customers', query: { group: customer.group.id } }" class="rounded-md bg-violet-50 px-2 py-0.5 text-[12px] font-medium text-violet-700 hover:bg-violet-100">{{ customer.group.name }}</RouterLink>
                    <Badge :tone="customerStatus[customer.status]?.tone" data-testid="customer-status">{{ customerStatus[customer.status]?.label }}</Badge>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-[14px] text-slate-500">
                    <span class="font-mono text-[13px] text-slate-400">{{ customer.code }}</span>
                    <span v-if="customer.phone" class="flex items-center gap-1.5"><Phone class="size-4" />{{ customer.phone }}</span>
                    <span v-if="customer.email" class="flex items-center gap-1.5 break-all"><Mail class="size-4" />{{ customer.email }}</span>
                    <span v-if="shortAddress" class="flex items-center gap-1.5"><MapPin class="size-4" />{{ shortAddress }}</span>
                    <span v-if="birthdayLabel" class="flex items-center gap-1.5"><Cake class="size-4" />{{ birthdayLabel }}</span>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-slate-500">
                    <span v-if="customer.channels?.length" class="flex items-center gap-1.5">Mua qua <ChannelDots :channels="customer.channels" /></span>
                    <span v-if="customer.assignee" class="flex items-center gap-1">Phụ trách <b class="font-medium text-slate-700">{{ customer.assignee.name }}</b></span>
                    <span v-if="customer.tags.length" class="flex flex-wrap items-center gap-1">Tags <RouterLink v-for="t in customer.tags" :key="t" :to="{ path: '/customers', query: { tag: t } }" class="rounded bg-slate-100 px-1.5 text-[12px] text-slate-600 hover:bg-slate-200">{{ t }}</RouterLink></span>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <button v-if="tenant.can('customers.delete') && !customer.orders_count" type="button" :class="cn(btnOutline, 'px-3 text-slate-500 hover:border-red-300 hover:text-red-600')" title="Xóa" aria-label="Xóa khách" @click="remove"><Trash2 class="size-4" /></button>
                <button v-if="canMerge" type="button" :class="btnOutline" data-testid="merge-customer" @click="pickOpen = true"><Merge class="size-4" /> Gộp khách</button>
                <button v-if="canManage" type="button" :class="btnOutline" data-testid="edit-customer" @click="dialog = true"><Pencil class="size-4" /> Sửa</button>
                <button v-if="tenant.can('orders.create')" type="button" :class="btnPrimary" @click="$router.push({ path: '/orders/new', query: { customer: customer.id } })"><Plus class="size-4" /> Tạo đơn cho khách</button>
            </div>
        </div>

        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="customer-kpis">
            <ReportKpi label="Tổng đã mua" tip="Tổng tiền các đơn của khách, không tính đơn hủy, đơn hoàn." :icon="ShoppingBag" primary :value="Math.round(Number(customer.total_spent ?? 0))">
                <template #chips><ReportChip tone="onblue">{{ formatNumber(customer.orders_count) }} đơn</ReportChip></template>
                Không tính đơn hủy, đơn hoàn
            </ReportKpi>
            <ReportKpi label="Trung bình mỗi đơn" tip="Tổng đã mua chia cho số đơn." :icon="Receipt" tone="blue" plain :value="customer.summary?.average_order ? Math.round(Number(customer.summary.average_order)) : null" :text="customer.summary?.average_order ? undefined : '—'">
                {{ (customer.summary?.purchase_interval_days ?? 0) > 0 ? `Khoảng ${formatNumber(customer.summary!.purchase_interval_days!)} ngày mua một lần` : 'Chưa đủ đơn để tính chu kỳ' }}
            </ReportKpi>
            <ReportKpi label="Lần mua gần nhất" tip="Ngày đặt đơn gần nhất của khách." :icon="Clock" tone="violet" plain :value="null" :text="lastOrderLabel" unit="">
                <template v-if="customer.orders[0]" #chips><ReportChip>{{ orderCode(customer.orders[0]) }}</ReportChip></template>
                {{ customer.last_order_at ? formatDate(customer.last_order_at) : 'Chưa có đơn' }}
            </ReportKpi>
            <ReportKpi label="Công nợ" tip="Số tiền khách còn phải trả cho các đơn kênh riêng và bán tại quầy." :icon="HandCoins" :tone="Number(customer.debt) > 0 ? 'red' : 'amber'" :value="Math.round(Number(customer.debt ?? 0))" data-testid="customer-debt-kpi">
                <template #chips>
                    <ReportChip v-if="Number(customer.overdue) > 0" tone="red"><span data-testid="customer-overdue">Quá hạn {{ formatMoney(customer.overdue) }}</span></ReportChip>
                    <ReportChip v-else :tone="Number(customer.debt) > 0 ? 'amber' : 'up'">{{ Number(customer.debt) > 0 ? 'Chưa đến hạn' : 'Không nợ' }}</ReportChip>
                </template>
                <span data-testid="customer-debt" class="sr-only">{{ formatMoney(customer.debt) }}</span>
                {{ customer.summary?.terms_days != null ? `Hạn thanh toán ${customer.summary.terms_days} ngày` : 'Chưa đặt hạn thanh toán' }}<template v-if="customer.summary?.credit_limit != null"> · hạn mức {{ formatMoney(customer.summary.credit_limit) }}</template>
            </ReportKpi>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
            <div class="min-w-0 space-y-5">
                <Tabs v-model="tab" :tabs="tabs" variant="underline" data-testid="customer-tabs" />

                <template v-if="tab === 'overview'">
                    <section :class="cn(card, 'p-5')">
                        <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Thói quen mua hàng</h3><span class="text-[13px] text-slate-400">Tính trên đơn đã giao, không tính đơn hủy</span></div>
                        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="customer-summary">
                            <div v-for="h in habits" :key="h.label" class="rounded-[12px] bg-slate-50 px-4 py-3"><dt class="text-[12.5px] text-slate-500">{{ h.label }}</dt><dd class="mt-1 text-[17px] font-semibold tabular-nums text-slate-900">{{ h.value }}</dd></div>
                        </dl>
                    </section>
                    <div class="grid gap-5 xl:grid-cols-2">
                        <section v-if="customer.summary?.top_products?.length" :class="cn(card, 'p-5')" data-testid="top-products">
                            <h3 class="mb-1 text-[16px] font-semibold text-slate-900">Sản phẩm hay mua</h3>
                            <ul class="divide-y divide-slate-100">
                                <li v-for="p in customer.summary.top_products" :key="`${p.sku}-${p.name}-${p.variant_name}`" class="flex items-center gap-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[14px] font-medium text-slate-800">{{ p.name }}<span v-if="p.variant_name" class="font-normal text-slate-400"> · {{ p.variant_name }}</span></p>
                                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-blue-500" :style="{ width: `${(Number(p.amount) / topMax) * 100}%` }" /></div>
                                    </div>
                                    <div class="w-32 shrink-0 text-right"><p class="text-[14px] font-semibold tabular-nums text-slate-900">{{ formatMoney(p.amount) }}</p><p class="text-[12px] text-slate-400">{{ formatNumber(p.quantity) }} sp · {{ p.orders }} đơn</p></div>
                                </li>
                            </ul>
                        </section>
                        <section :class="cn(card, 'overflow-hidden', !customer.summary?.top_products?.length && 'xl:col-span-2')">
                            <div class="flex items-center justify-between px-5 pb-2 pt-5"><h3 class="text-[16px] font-semibold text-slate-900">Đơn gần đây</h3><button v-if="customer.orders.length" type="button" class="text-[13px] text-blue-600 hover:underline" @click="tab = 'orders'">{{ formatNumber(customer.orders_count) }} đơn</button></div>
                            <ul v-if="customer.orders.length" class="divide-y divide-slate-100">
                                <li v-for="o in customer.orders.slice(0, 5)" :key="o.id" class="flex cursor-pointer items-center gap-3 px-5 py-3 hover:bg-slate-50" @click="$router.push(`/orders/${o.id}`)">
                                    <span class="font-mono text-[13px] text-blue-700">{{ orderCode(o) }}</span>
                                    <span class="flex-1 truncate text-[13.5px] text-slate-500">{{ formatDateTime(o.placed_at) }}</span>
                                    <Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label }}</Badge>
                                    <span class="w-28 text-right text-[14px] font-semibold tabular-nums">{{ formatMoney(o.total) }}</span>
                                </li>
                            </ul>
                            <p v-else class="px-5 py-4 text-[14px] text-slate-500">Chưa có đơn.</p>
                        </section>
                    </div>
                </template>

                <Card v-else-if="tab === 'orders'">
                    <ErrorState v-if="ordersPage.error.value" :message="ordersPage.error.value" @retry="ordersPage.load(ordersPage.meta.value.current_page)" />
                    <div v-else-if="!ordersPage.loaded.value" class="p-4"><Skeleton class="h-24" /></div>
                    <div v-else-if="ordersPage.rows.value.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead :class="thead"><tr><th :class="th">Mã đơn</th><th :class="th">Ngày</th><th :class="th">Trạng thái</th><th :class="th">Thanh toán</th><th :class="[th, 'text-right']">Tổng</th></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="o in ordersPage.rows.value" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/orders/${o.id}`)">
                                    <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ orderCode(o) }}<div class="font-sans text-muted-foreground">{{ o.channel?.name }}</div></td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(o.placed_at) }}</td>
                                    <td class="px-4 py-2.5"><Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label }}</Badge></td>
                                    <td class="px-4 py-2.5"><Badge v-if="o.payment_status" :tone="paymentStatus[o.payment_status]?.tone">{{ paymentStatus[o.payment_status]?.label }}</Badge></td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có đơn.</p>
                    <Pager :meta="ordersPage.meta.value" :loading="ordersPage.loading.value" @page="ordersPage.load" />
                </Card>

                <Card v-else-if="tab === 'debt'" data-testid="customer-ledger">
                    <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2.5">
                        <Button v-if="canCollect && Number(customer.debt) > 0" size="sm" data-testid="debt-collect" @click="receiptDialog = true"><HandCoins /> Thu nợ</Button>
                        <Button v-if="canAdjust && ledger !== null && !hasOpening" variant="outline" size="sm" data-testid="debt-opening" @click="newEntry('opening')"><Plus /> Nợ đầu kỳ</Button>
                        <Button v-if="canAdjust" variant="outline" size="sm" data-testid="debt-adjust" @click="newEntry('adjustment')"><Pencil /> Điều chỉnh</Button>
                        <Button variant="ghost" size="sm" class="ml-auto" :loading="exporting" @click="exportLedger"><Download /> Xuất Excel</Button>
                    </div>
                    <ErrorState v-if="ledgerError" :message="ledgerError" @retry="loadLedger" />
                    <div v-else-if="ledger === null" class="p-4"><Skeleton class="h-24" /></div>
                    <p v-else-if="!ledger.length" class="px-5 py-4 text-sm text-muted-foreground">Chưa phát sinh công nợ.</p>
                    <div v-else class="overflow-x-auto">
                        <p class="border-b px-5 py-2.5 text-xs text-muted-foreground">Đơn bán qua kênh riêng (trừ đơn hủy, hoàn toàn bộ, giao thất bại và đơn COD) cộng vào nợ; trả hàng và thu tiền trừ đi, hoàn tiền cộng lại. Nợ đầu kỳ và phiếu điều chỉnh là chứng từ riêng, chỉ hủy được kèm lý do. Mới nhất ở trên.</p>
                        <table class="w-full text-sm">
                            <thead :class="thead"><tr><th :class="th">Thời gian</th><th :class="th">Chứng từ</th><th :class="th">Diễn giải</th><th :class="[th, 'text-right']">Phát sinh</th><th :class="[th, 'text-right']">Còn nợ</th><th class="w-10" /></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="(l, i) in ledger" :key="i">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(l.at) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs"><RouterLink v-if="l.order_id" :to="`/orders/${l.order_id}`" class="text-primary hover:underline">{{ l.ref }}</RouterLink><span v-else>{{ l.ref }}</span></td>
                                    <td class="px-4 py-2.5">{{ l.note }}</td>
                                    <td :class="cn('whitespace-nowrap px-4 py-2.5 text-right tabular-nums', Number(l.change) < 0 ? 'text-success' : '')">{{ Number(l.change) > 0 ? '+' : '' }}{{ formatMoney(l.change) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(l.balance) }}</td>
                                    <td class="px-2 py-1.5"><Button v-if="canAdjust && l.entry_id && l.type !== 'receipt'" variant="ghost" size="sm" :aria-label="`Hủy ${l.ref}`" title="Hủy chứng từ" @click="voidEntry(l)"><Ban /></Button></td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="border-t px-5 py-2.5 text-xs text-muted-foreground">Phiếu thu nợ đầu kỳ nằm trong Sổ quỹ; hủy phiếu ở đó thì khoản nợ được trả lại.</p>
                    </div>
                </Card>

                <Card v-else-if="tab === 'payments'">
                    <ErrorState v-if="paymentsPage.error.value" :message="paymentsPage.error.value" @retry="paymentsPage.load()" />
                    <div v-else-if="!paymentsPage.loaded.value" class="p-4"><Skeleton class="h-24" /></div>
                    <ul v-else-if="paymentsPage.rows.value.length" class="divide-y text-sm">
                        <li v-for="p in paymentsPage.rows.value" :key="p.id" class="flex items-center justify-between gap-3 px-5 py-2.5" :class="p.voided_at && 'opacity-50 line-through'">
                            <div class="min-w-0">
                                <div>{{ p.kind === 'refund' ? 'Hoàn' : 'Thu' }} · {{ paymentMethodNames[p.method] ?? p.method }}<span v-if="p.reference" class="text-muted-foreground"> · {{ p.reference }}</span></div>
                                <div class="text-xs text-muted-foreground"><RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="text-primary hover:underline">{{ p.order.number }}</RouterLink> · {{ formatDateTime(p.paid_at) }}</div>
                            </div>
                            <span class="whitespace-nowrap tabular-nums" :class="p.kind === 'refund' ? 'text-destructive' : 'text-success'">{{ p.kind === 'refund' ? '−' : '+' }}{{ formatMoney(p.amount) }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có phiếu thu hoặc hoàn tiền.</p>
                    <Pager :meta="paymentsPage.meta.value" :loading="paymentsPage.loading.value" @page="paymentsPage.load" />
                </Card>

                <Card v-else-if="tab === 'returns'">
                    <ErrorState v-if="returnsPage.error.value" :message="returnsPage.error.value" @retry="returnsPage.load()" />
                    <div v-else-if="!returnsPage.loaded.value" class="p-4"><Skeleton class="h-24" /></div>
                    <div v-else-if="returnsPage.rows.value.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead :class="thead"><tr><th :class="th">Phiếu</th><th :class="th">Đơn gốc</th><th :class="th">Loại</th><th :class="th">Trạng thái</th><th :class="[th, 'text-right']">Giá trị</th></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="r in returnsPage.rows.value" :key="r.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/returns/${r.id}`)">
                                    <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ r.number }}<div class="font-sans text-muted-foreground">{{ formatDateTime(r.created_at) }}</div></td>
                                    <td class="px-4 py-2.5 font-mono text-xs">{{ orderCode(r.order) }}</td>
                                    <td class="px-4 py-2.5">{{ returnTypes[r.type] ?? r.type }}</td>
                                    <td class="px-4 py-2.5"><Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label ?? r.status }}</Badge></td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.refund_amount ?? r.goods_value ?? 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Khách chưa trả hàng.</p>
                    <Pager :meta="returnsPage.meta.value" :loading="returnsPage.loading.value" @page="returnsPage.load" />
                </Card>

                <Card v-else-if="tab === 'addresses'">
                    <ul class="divide-y text-sm">
                        <li v-if="customer.address" class="flex items-start gap-3 px-5 py-3">
                            <MapPin class="mt-0.5 size-4 text-muted-foreground" />
                            <div class="flex-1"><div class="font-medium">Địa chỉ chính <Badge class="ml-1">Mặc định</Badge><Badge v-if="!customer.ward_code" tone="warning" class="ml-1">Chưa chuẩn hóa</Badge></div><div class="text-muted-foreground">{{ customer.address }}</div></div>
                        </li>
                        <li v-for="a in customer.addresses" :key="a.id" class="flex items-start gap-3 px-5 py-3">
                            <MapPin class="mt-0.5 size-4 text-muted-foreground" />
                            <div class="flex-1">
                                <div class="font-medium">{{ a.label || 'Địa chỉ giao hàng' }}<span v-if="a.recipient || a.phone" class="font-normal text-muted-foreground"> · {{ [a.recipient, a.phone].filter(Boolean).join(' · ') }}</span></div>
                                <div class="text-muted-foreground">{{ a.address }} <Badge v-if="!a.ward_code" tone="warning" class="ml-1">Chưa chuẩn hóa</Badge></div>
                            </div>
                            <template v-if="canManage">
                                <Button variant="ghost" size="sm" aria-label="Sửa địa chỉ" @click="editAddress(a)"><Pencil /></Button>
                                <Button variant="ghost" size="sm" aria-label="Xóa địa chỉ" @click="removeAddress(a)"><Trash2 /></Button>
                            </template>
                        </li>
                    </ul>
                    <p v-if="!customer.address && !customer.addresses.length" class="px-5 py-4 text-sm text-muted-foreground">Chưa có địa chỉ.</p>
                    <div v-if="canManage" class="border-t px-5 py-2.5"><Button variant="outline" size="sm" data-testid="add-address" @click="editAddress(null)"><Plus /> Thêm địa chỉ giao hàng</Button></div>
                </Card>

                <Card v-if="tab === 'notes'" title="Việc cần làm với khách" data-testid="customer-tasks">
                    <form class="grid gap-2 border-b p-4 sm:grid-cols-[1fr_12rem_auto]" @submit.prevent="addTask">
                        <Input v-model="taskForm.title" placeholder="Gọi lại báo giá, nhắc thanh toán…" maxlength="200" data-testid="task-title" />
                        <DateTimeInput v-model="taskForm.due_at" aria-label="Hạn" data-testid="task-due" class="w-64" />
                        <Button type="submit" variant="outline" :loading="taskSaving" :disabled="!taskForm.title.trim() || !taskForm.due_at" data-testid="task-add"><CalendarClock /> Hẹn</Button>
                    </form>
                    <ul v-if="tasks.length" class="divide-y text-sm">
                        <li v-for="t in tasks" :key="t.id" class="flex items-center gap-3 px-5 py-2.5" :class="t.done_at && 'opacity-60'">
                            <input type="checkbox" class="size-4" :checked="!!t.done_at" :disabled="!!t.done_at" :aria-label="`Xong: ${t.title}`" @change="doneTask(t)" />
                            <div class="min-w-0 flex-1">
                                <p :class="t.done_at && 'line-through'">{{ t.title }}</p>
                                <p class="text-xs" :class="t.late ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ t.late ? 'Trễ hẹn · ' : '' }}{{ formatDateTime(t.due_at) }}<template v-if="t.assignee"> · {{ t.assignee }}</template></p>
                            </div>
                            <Button variant="ghost" size="sm" aria-label="Xóa việc" @click="removeTask(t)"><Trash2 /></Button>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có việc nào.</p>
                </Card>
                <Card v-if="tab === 'notes'">
                    <form class="flex gap-2 border-b p-4" @submit.prevent="addNote">
                        <Select v-model="noteKind" class="w-32 shrink-0" aria-label="Loại liên hệ" :options="Object.entries(noteKinds).map(([value, k]) => ({ value, label: k.label }))" data-testid="note-kind" />
                        <Input v-model="noteBody" placeholder="Ghi lại cuộc gọi, khiếu nại, sở thích của khách…" maxlength="2000" data-testid="note-input" />
                        <Button type="submit" :loading="noteSaving" :disabled="!noteBody.trim()" data-testid="note-add">Thêm</Button>
                    </form>
                    <ul v-if="customer.notes.length" class="divide-y text-sm">
                        <li v-for="n in customer.notes" :key="n.id" class="flex items-start gap-3 px-5 py-3">
                            <div class="flex-1">
                                <p class="whitespace-pre-line"><Badge v-if="n.kind && n.kind !== 'note'" :tone="noteKinds[n.kind]?.tone" class="mr-1.5">{{ noteKinds[n.kind]?.label }}</Badge>{{ n.body }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ n.author ?? 'Không rõ' }} · {{ formatDateTime(n.created_at) }}</p>
                            </div>
                            <Button v-if="n.mine || canManage" variant="ghost" size="sm" aria-label="Xóa ghi chú" @click="removeNote(n)"><Trash2 /></Button>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có ghi chú.</p>
                </Card>
                <Card v-if="tab === 'notes'" title="Lịch sử hoạt động" data-testid="customer-activity">
                    <ErrorState v-if="activityPage.error.value" :message="activityPage.error.value" @retry="activityPage.load()" />
                    <div v-else-if="!activityPage.loaded.value" class="p-4"><Skeleton class="h-16" /></div>
                    <ol v-else-if="activityPage.rows.value.length" class="divide-y text-sm">
                        <li v-for="a in activityPage.rows.value" :key="a.id" class="px-5 py-2.5">
                            <div><span class="font-medium">{{ activityLabels[a.action] ?? a.action }}</span><span v-if="activityDetail(a)" class="text-muted-foreground"> · {{ activityDetail(a) }}</span></div>
                            <div class="text-xs text-muted-foreground">{{ a.actor ?? 'Hệ thống' }} · {{ formatDateTime(a.at) }}</div>
                        </li>
                    </ol>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có hoạt động.</p>
                    <Pager :meta="activityPage.meta.value" :loading="activityPage.loading.value" @page="activityPage.load" />
                </Card>
            </div>

            <div class="space-y-5">
                <div v-if="Number(customer.debt) > 0" class="rounded-[14px] border border-red-200 bg-red-50/60 p-5" data-testid="customer-debt-alert">
                    <p class="flex items-center gap-2 font-semibold text-destructive"><TriangleAlert class="size-4" /> Công nợ {{ formatMoney(customer.debt) }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <template v-if="Number(customer.overdue) > 0">Quá hạn {{ formatMoney(customer.overdue) }}.</template>
                        <template v-if="customer.summary?.terms_days != null"> Hạn thanh toán {{ customer.summary.terms_days }} ngày.</template>
                    </p>
                    <div class="mt-3 flex gap-2">
                        <Button variant="outline" size="sm" @click="tab = 'debt'">Xem sổ nợ</Button>
                        <Button v-if="canCollect" size="sm" data-testid="debt-alert-collect" @click="receiptDialog = true"><HandCoins /> Thu nợ</Button>
                    </div>
                </div>
                <CustomerLoyaltyCard :customer-id="customer.id" :customer-name="customer.name" />
                <Card title="Nhóm và điều khoản">
                    <dl v-if="customer.group" class="space-y-2 px-5 py-4 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Nhóm</dt><dd><RouterLink :to="{ path: '/customers', query: { group: customer.group.id } }" class="text-primary hover:underline">{{ customer.group.name }}</RouterLink></dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Giá áp dụng</dt><dd>{{ priceTierLabels[customer.group.price_tier] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Chiết khấu</dt><dd>{{ Number(customer.group.discount_percent) ? `${Number(customer.group.discount_percent)}%` : '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Thanh toán quen dùng</dt><dd>{{ customer.group.payment_method ? paymentMethods[customer.group.payment_method] : '—' }}</dd></div>
                    </dl>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa xếp nhóm.<button v-if="canManage" type="button" class="ml-1 text-primary hover:underline" @click="dialog = true">Xếp nhóm</button></p>
                    <dl class="space-y-2 border-t px-5 py-4 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Hạn thanh toán</dt><dd>{{ customer.summary?.terms_days != null ? `${customer.summary.terms_days} ngày` : '—' }}<span v-if="customer.payment_terms_days == null && customer.summary?.terms_days != null" class="text-[12.5px] text-slate-400"> (theo nhóm)</span></dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Hạn mức nợ</dt><dd class="tabular-nums">{{ customer.summary?.credit_limit != null ? formatMoney(customer.summary.credit_limit) : '—' }}<span v-if="customer.credit_limit == null && customer.summary?.credit_limit != null" class="text-[12.5px] text-slate-400"> (theo nhóm)</span></dd></div>
                    </dl>
                </Card>
                <Card title="Thông tin">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 text-sm">
                        <div><dt class="text-[12.5px] text-slate-400">Điện thoại</dt><dd>{{ customer.phone ?? '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Email</dt><dd class="break-all">{{ customer.email ?? '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Ngày sinh</dt><dd>{{ customer.birthday ? formatDate(customer.birthday) : '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Giới tính</dt><dd>{{ customer.gender ? genderLabels[customer.gender] : '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Loại khách</dt><dd>{{ customerTypes[customer.type ?? 'individual'] }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Nguồn</dt><dd>{{ customer.source ? (customerSources[customer.source] ?? customer.source) : '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Mã số thuế</dt><dd>{{ customer.tax_code ?? '—' }}</dd></div>
                        <div><dt class="text-[12.5px] text-slate-400">Phụ trách</dt><dd>{{ customer.assignee?.name ?? '—' }}</dd></div>
                        <div class="col-span-2"><dt class="text-[12.5px] text-slate-400">Địa chỉ</dt><dd>{{ customer.address ?? '—' }}</dd></div>
                        <div v-if="customer.invoice?.name || customer.invoice?.address || customer.invoice?.email" class="col-span-2" data-testid="customer-invoice">
                            <dt class="text-[12.5px] text-slate-400">Xuất hóa đơn</dt>
                            <dd>{{ customer.invoice.name }}<span v-if="customer.invoice.address" class="block text-muted-foreground">{{ customer.invoice.address }}</span><span v-if="customer.invoice.email" class="block break-all text-muted-foreground">{{ customer.invoice.email }}</span></dd>
                        </div>
                        <div v-if="customer.tags.length" class="col-span-2">
                            <dt class="text-[12.5px] text-slate-400">Tags</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <RouterLink v-for="t in customer.tags" :key="t" :to="{ path: '/customers', query: { tag: t } }" class="rounded-full bg-muted px-2 py-0.5 text-xs hover:bg-secondary">{{ t }}</RouterLink>
                            </dd>
                        </div>
                        <div v-if="customer.note" class="col-span-2"><dt class="text-[12.5px] text-slate-400">Ghi chú</dt><dd>{{ customer.note }}</dd></div>
                        <div class="col-span-2"><dt class="text-[12.5px] text-slate-400">Ngày thêm</dt><dd>{{ formatDateTime(customer.created_at) }}</dd></div>
                    </dl>
                </Card>
                <Card v-if="links && (links.identities.length || links.merges.length)" title="Kênh liên kết và lịch sử gộp" data-testid="customer-links">
                    <ul class="divide-y text-sm">
                        <li v-for="i in links.identities" :key="i.source + i.external_id" class="flex items-center gap-2 px-5 py-2.5">
                            <Link2 class="size-4 text-muted-foreground" />
                            <span class="flex-1">{{ marketplaceNames[i.source] ?? i.source }} · <span class="font-mono text-xs">{{ i.label ?? i.external_id }}</span></span>
                            <span class="text-[12.5px] text-slate-400">{{ i.last_seen_at ? formatDate(i.last_seen_at) : '' }}</span>
                        </li>
                        <li v-for="m in links.merges" :key="m.id" class="flex items-center gap-2 px-5 py-2.5">
                            <Merge class="size-4 text-muted-foreground" />
                            <span class="flex-1">Đã gộp {{ m.name }} <span class="font-mono text-[12.5px] text-slate-400">{{ m.code }}</span></span>
                            <span class="text-[12.5px] text-slate-400">{{ m.by ?? '' }} · {{ formatDate(m.at) }}</span>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <CustomerDialog v-model:open="dialog" :customer="customer" @saved="load" />
        <DebtReceiptDialog v-model:open="receiptDialog" :customer-id="customer.id" :debt="Number(customer.debt ?? 0)" @saved="debtChanged" />
        <Dialog v-model:open="pickOpen" title="Gộp khách khác vào khách này" :description="`Tìm thẻ khách trùng để gộp vào ${customer.name} (${customer.code}). Thẻ được tìm sẽ bị gộp và xóa khỏi danh sách.`">
            <Input v-model="pickQuery" placeholder="Tên, mã khách, email, số điện thoại…" data-testid="merge-search" />
            <ul v-if="pickResults.length" class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-[12px] border border-slate-200 text-[14px]">
                <li v-for="c in pickResults" :key="c.id">
                    <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-slate-50" @click="pickForMerge(c)">
                        <span><span class="font-medium">{{ c.name }}</span> <span class="font-mono text-xs text-muted-foreground">{{ c.code }}</span><span class="block text-xs text-muted-foreground">{{ [c.phone, c.email].filter(Boolean).join(' · ') }}</span></span>
                        <span class="text-xs text-muted-foreground">{{ formatNumber(c.orders_count) }} đơn</span>
                    </button>
                </li>
            </ul>
        </Dialog>
        <MergeDialog v-model:open="mergeOpen" :keep-id="customer.id" :source-id="mergeSource" @merged="merged" />
        <DebtEntryDialog v-model:open="entryDialog" :customer-id="customer.id" :kind="entryKind" @saved="debtChanged" />
        <Dialog v-model:open="addressDialog" :title="editingAddress ? 'Sửa địa chỉ giao hàng' : 'Thêm địa chỉ giao hàng'" description="Số điện thoại và địa chỉ được mã hóa khi lưu.">
            <form id="address-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveAddress">
                <FormField label="Tên gợi nhớ" for="a-label"><Input id="a-label" v-model="addressForm.label" placeholder="Nhà riêng, Công ty…" /></FormField>
                <FormField label="Người nhận" for="a-recipient"><Input id="a-recipient" v-model="addressForm.recipient" /></FormField>
                <FormField label="Số điện thoại nhận" for="a-phone" class="sm:col-span-2" :error="addressError?.field('phone')"><Input id="a-phone" v-model="addressForm.phone" inputmode="tel" :invalid="!!addressError?.field('phone')" /></FormField>
                <div class="sm:col-span-2">
                    <AddressPicker
                        v-model="addressParts"
                        id-prefix="a-addr"
                        :legacy="addressLegacy"
                        :errors="{ street: addressError?.field('street'), province_code: addressError?.field('province_code'), ward_code: addressError?.field('ward_code') ?? addressError?.field('address') }"
                    />
                </div>
            </form>
            <template #footer>
                <Button variant="outline" @click="addressDialog = false">Hủy</Button>
                <Button type="submit" form="address-form" :loading="addressSaving" data-testid="a-save">Lưu</Button>
            </template>
        </Dialog>
    </template>
</template>
