<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Ban, Banknote, CalendarClock, Download, HandCoins, MapPin, Pencil, Plus, ShoppingCart, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import CustomerDialog from './CustomerDialog.vue';
import DebtEntryDialog from './DebtEntryDialog.vue';
import DebtReceiptDialog from './DebtReceiptDialog.vue';
import Pager from './Pager.vue';
import { usePaged } from './usePaged';
import { customerStatus, genderLabels, priceTierLabels } from './labels';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { orderStatus, paymentMethodNames, paymentMethods, paymentStatus, returnStatus, returnTypes } from '../orders/labels';
import type { Customer, CustomerActivity, CustomerAddress, CustomerLedgerEntry, CustomerNote, CustomerSummary, OrderPaymentData, OrderReturnData, OrderSummary } from '@/shared/types';

type Detail = Customer & { orders: OrderSummary[]; payments: OrderPaymentData[]; addresses: CustomerAddress[]; notes: CustomerNote[]; summary?: CustomerSummary };

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('customers.manage'));
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
    if (c.reason) parts.push(String(c.reason));
    return parts.join(' · ');
}

// Extra delivery addresses.
const addressDialog = ref(false);
const editingAddress = ref<CustomerAddress | null>(null);
const addressForm = reactive({ label: '', recipient: '', phone: '', address: '' });
const addressError = ref<ApiError | null>(null);
const addressSaving = ref(false);
function editAddress(a: CustomerAddress | null) {
    editingAddress.value = a;
    addressError.value = null;
    Object.assign(addressForm, { label: a?.label ?? '', recipient: a?.recipient ?? '', phone: a?.phone ?? '', address: a?.address ?? '' });
    addressDialog.value = true;
}
async function saveAddress() {
    addressSaving.value = true;
    addressError.value = null;
    const body = Object.fromEntries(Object.entries(addressForm).map(([k, v]) => [k, v === '' ? null : v]));
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
const noteSaving = ref(false);
async function addNote() {
    if (!noteBody.value.trim()) return;
    noteSaving.value = true;
    try {
        const { data } = await api.post(`/customers/${customer.value!.id}/notes`, { body: noteBody.value.trim() });
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

const tabs = computed(() => [
    { value: 'overview', label: 'Tổng quan' },
    { value: 'orders', label: 'Đơn hàng', count: customer.value?.orders_count ?? null },
    { value: 'debt', label: 'Công nợ' },
    { value: 'payments', label: 'Thanh toán' },
    { value: 'returns', label: 'Trả hàng' },
    { value: 'addresses', label: 'Địa chỉ', count: (customer.value?.addresses.length ?? 0) + (customer.value?.address ? 1 : 0) },
    { value: 'notes', label: 'Ghi chú và hoạt động', count: customer.value?.notes.length ?? null },
]);
const th = 'px-4 py-2 font-medium';
const thead = 'border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground';
</script>

<template>
    <div v-if="loading && !customer" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="customer">
        <RouterLink to="/customers" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Khách hàng</RouterLink>
        <PageHeader :title="customer.name" :description="`${customer.code}${customer.phone ? ' · ' + customer.phone : ''}${customer.group ? ' · nhóm ' + customer.group.name : ''}`">
            <Badge :tone="customerStatus[customer.status]?.tone" data-testid="customer-status">{{ customerStatus[customer.status]?.label }}</Badge>
            <Button v-if="tenant.can('orders.create')" @click="$router.push({ path: '/orders/new', query: { customer: customer.id } })"><Plus /> Tạo đơn</Button>
            <Button v-if="canManage" variant="outline" data-testid="edit-customer" @click="dialog = true"><Pencil /> Sửa</Button>
            <Button v-if="tenant.can('customers.delete') && !customer.orders_count" variant="ghost" title="Xóa" @click="remove"><Trash2 /></Button>
        </PageHeader>

        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard label="Số đơn" :icon="ShoppingCart" :value="formatNumber(customer.orders_count)" />
            <KpiCard label="Tổng chi tiêu (không tính đơn hủy/hoàn)" :icon="Banknote" tone="blue" :value="formatMoney(customer.total_spent)" />
            <KpiCard label="Còn nợ" :icon="HandCoins" :tone="Number(customer.debt) > 0 ? 'red' : 'slate'" :value="formatMoney(customer.debt)"><template #value><span data-testid="customer-debt">{{ formatMoney(customer.debt) }}</span></template><span v-if="Number(customer.overdue) > 0" class="font-medium text-destructive" data-testid="customer-overdue">Quá hạn {{ formatMoney(customer.overdue) }}</span></KpiCard>
            <KpiCard label="Mua gần nhất" :icon="CalendarClock" :value="customer.last_order_at ? formatDate(customer.last_order_at) : null" />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <div class="rounded-lg border bg-card px-3 shadow-xs"><Tabs v-model="tab" :tabs="tabs" variant="underline" data-testid="customer-tabs" /></div>

                <template v-if="tab === 'overview'">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Card title="Thói quen mua hàng">
                            <dl class="space-y-2 px-5 py-4 text-sm" data-testid="customer-summary">
                                <div class="flex justify-between"><dt class="text-muted-foreground">Đơn đầu tiên</dt><dd>{{ customer.summary?.first_order_at ? formatDate(customer.summary.first_order_at) : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Giá trị trung bình mỗi đơn</dt><dd class="tabular-nums">{{ customer.summary?.average_order ? formatMoney(customer.summary.average_order) : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Chu kỳ mua</dt><dd>{{ customer.summary?.purchase_interval_days != null ? `${formatNumber(customer.summary.purchase_interval_days)} ngày/lần` : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Chưa mua lại</dt><dd>{{ customer.summary?.days_since_last_order != null ? `${formatNumber(customer.summary.days_since_last_order)} ngày` : '—' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Giá trị hàng trả</dt><dd class="tabular-nums">{{ formatMoney(customer.summary?.returned_value ?? 0) }}</dd></div>
                            </dl>
                        </Card>
                        <Card title="Công nợ">
                            <dl class="space-y-2 px-5 py-4 text-sm">
                                <div class="flex justify-between"><dt class="text-muted-foreground">Còn nợ</dt><dd class="font-semibold tabular-nums">{{ formatMoney(customer.debt) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Quá hạn</dt><dd :class="cn('tabular-nums', Number(customer.overdue) > 0 && 'font-semibold text-destructive')">{{ formatMoney(customer.overdue ?? 0) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Hạn thanh toán</dt><dd>{{ customer.summary?.terms_days != null ? `${customer.summary.terms_days} ngày` : 'Chưa đặt' }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">Hạn mức nợ</dt><dd class="tabular-nums">{{ customer.summary?.credit_limit != null ? formatMoney(customer.summary.credit_limit) : 'Không giới hạn' }}</dd></div>
                                <div v-if="creditUse !== null">
                                    <div class="h-1.5 overflow-hidden rounded-full bg-muted"><div :class="cn('h-full rounded-full', creditUse >= 100 ? 'bg-destructive' : creditUse >= 80 ? 'bg-amber-500' : 'bg-primary')" :style="{ width: `${creditUse}%` }" /></div>
                                    <p class="mt-1 text-xs text-muted-foreground">Đã dùng {{ creditUse }}% hạn mức</p>
                                </div>
                            </dl>
                            <div class="border-t px-5 py-2.5"><button type="button" class="text-sm text-primary hover:underline" @click="tab = 'debt'">Xem sổ công nợ</button></div>
                        </Card>
                    </div>
                    <Card title="Đơn gần đây">
                        <ul v-if="customer.orders.length" class="divide-y text-sm">
                            <li v-for="o in customer.orders.slice(0, 5)" :key="o.id" class="flex cursor-pointer items-center gap-3 px-5 py-2.5 hover:bg-muted/30" @click="$router.push(`/orders/${o.id}`)">
                                <span class="font-mono text-xs font-medium">{{ o.external_order_id }}</span>
                                <span class="flex-1 text-muted-foreground">{{ formatDateTime(o.placed_at) }}</span>
                                <Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label }}</Badge>
                                <span class="w-28 text-right tabular-nums">{{ formatMoney(o.total) }}</span>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có đơn.</p>
                    </Card>
                </template>

                <Card v-else-if="tab === 'orders'">
                    <ErrorState v-if="ordersPage.error.value" :message="ordersPage.error.value" @retry="ordersPage.load(ordersPage.meta.value.current_page)" />
                    <div v-else-if="!ordersPage.loaded.value" class="p-4"><Skeleton class="h-24" /></div>
                    <div v-else-if="ordersPage.rows.value.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead :class="thead"><tr><th :class="th">Mã đơn</th><th :class="th">Ngày</th><th :class="th">Trạng thái</th><th :class="th">Thanh toán</th><th :class="[th, 'text-right']">Tổng</th></tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="o in ordersPage.rows.value" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/orders/${o.id}`)">
                                    <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ o.external_order_id }}<div class="font-sans text-muted-foreground">{{ o.channel?.name }}</div></td>
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
                                    <td class="px-4 py-2.5 font-mono text-xs">{{ r.order?.external_order_id }}</td>
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
                            <div class="flex-1"><div class="font-medium">Địa chỉ chính <Badge class="ml-1">Mặc định</Badge></div><div class="text-muted-foreground">{{ customer.address }}</div></div>
                        </li>
                        <li v-for="a in customer.addresses" :key="a.id" class="flex items-start gap-3 px-5 py-3">
                            <MapPin class="mt-0.5 size-4 text-muted-foreground" />
                            <div class="flex-1">
                                <div class="font-medium">{{ a.label || 'Địa chỉ giao hàng' }}<span v-if="a.recipient || a.phone" class="font-normal text-muted-foreground"> · {{ [a.recipient, a.phone].filter(Boolean).join(' · ') }}</span></div>
                                <div class="text-muted-foreground">{{ a.address }}</div>
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

                <Card v-else-if="tab === 'notes'">
                    <form class="flex gap-2 border-b p-4" @submit.prevent="addNote">
                        <Input v-model="noteBody" placeholder="Ghi lại cuộc gọi, khiếu nại, sở thích của khách…" maxlength="2000" data-testid="note-input" />
                        <Button type="submit" :loading="noteSaving" :disabled="!noteBody.trim()" data-testid="note-add">Thêm</Button>
                    </form>
                    <ul v-if="customer.notes.length" class="divide-y text-sm">
                        <li v-for="n in customer.notes" :key="n.id" class="flex items-start gap-3 px-5 py-3">
                            <div class="flex-1">
                                <p class="whitespace-pre-line">{{ n.body }}</p>
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

            <div class="space-y-4">
                <Card title="Thông tin">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Điện thoại</dt><dd>{{ customer.phone ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Email</dt><dd class="break-all">{{ customer.email ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Ngày sinh</dt><dd>{{ customer.birthday ? formatDate(customer.birthday) : '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Giới tính</dt><dd>{{ customer.gender ? genderLabels[customer.gender] : '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Mã số thuế</dt><dd>{{ customer.tax_code ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Phụ trách</dt><dd>{{ customer.assignee?.name ?? '—' }}</dd></div>
                        <div class="col-span-2"><dt class="text-xs text-muted-foreground">Địa chỉ</dt><dd>{{ customer.address ?? '—' }}</dd></div>
                        <div v-if="customer.tags.length" class="col-span-2">
                            <dt class="text-xs text-muted-foreground">Tags</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                <RouterLink v-for="t in customer.tags" :key="t" :to="{ path: '/customers', query: { tag: t } }" class="rounded-full bg-muted px-2 py-0.5 text-xs hover:bg-secondary">{{ t }}</RouterLink>
                            </dd>
                        </div>
                        <div v-if="customer.note" class="col-span-2"><dt class="text-xs text-muted-foreground">Ghi chú</dt><dd>{{ customer.note }}</dd></div>
                        <div class="col-span-2"><dt class="text-xs text-muted-foreground">Ngày thêm</dt><dd>{{ formatDateTime(customer.created_at) }}</dd></div>
                    </dl>
                </Card>
                <Card title="Nhóm và điều khoản mua hàng">
                    <dl v-if="customer.group" class="space-y-2 px-5 py-4 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Nhóm</dt><dd><RouterLink :to="{ path: '/customers', query: { group: customer.group.id } }" class="text-primary hover:underline">{{ customer.group.name }}</RouterLink></dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Giá áp dụng</dt><dd>{{ priceTierLabels[customer.group.price_tier] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Chiết khấu</dt><dd>{{ Number(customer.group.discount_percent) ? `${Number(customer.group.discount_percent)}%` : '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Thanh toán quen dùng</dt><dd>{{ customer.group.payment_method ? paymentMethods[customer.group.payment_method] : '—' }}</dd></div>
                    </dl>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa xếp nhóm.<button v-if="canManage" type="button" class="ml-1 text-primary hover:underline" @click="dialog = true">Xếp nhóm</button></p>
                    <dl class="space-y-2 border-t px-5 py-4 text-sm">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Hạn thanh toán</dt><dd>{{ customer.summary?.terms_days != null ? `${customer.summary.terms_days} ngày` : '—' }}<span v-if="customer.payment_terms_days == null && customer.summary?.terms_days != null" class="text-xs text-muted-foreground"> (theo nhóm)</span></dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Hạn mức nợ</dt><dd class="tabular-nums">{{ customer.summary?.credit_limit != null ? formatMoney(customer.summary.credit_limit) : '—' }}<span v-if="customer.credit_limit == null && customer.summary?.credit_limit != null" class="text-xs text-muted-foreground"> (theo nhóm)</span></dd></div>
                    </dl>
                </Card>
            </div>
        </div>

        <CustomerDialog v-model:open="dialog" :customer="customer" @saved="load" />
        <DebtReceiptDialog v-model:open="receiptDialog" :customer-id="customer.id" :debt="Number(customer.debt ?? 0)" @saved="debtChanged" />
        <DebtEntryDialog v-model:open="entryDialog" :customer-id="customer.id" :kind="entryKind" @saved="debtChanged" />
        <Dialog v-model:open="addressDialog" :title="editingAddress ? 'Sửa địa chỉ giao hàng' : 'Thêm địa chỉ giao hàng'" description="Số điện thoại và địa chỉ được mã hóa khi lưu.">
            <form id="address-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveAddress">
                <FormField label="Tên gợi nhớ" for="a-label"><Input id="a-label" v-model="addressForm.label" placeholder="Nhà riêng, Công ty…" /></FormField>
                <FormField label="Người nhận" for="a-recipient"><Input id="a-recipient" v-model="addressForm.recipient" /></FormField>
                <FormField label="Số điện thoại nhận" for="a-phone" class="sm:col-span-2" :error="addressError?.field('phone')"><Input id="a-phone" v-model="addressForm.phone" inputmode="tel" /></FormField>
                <FormField label="Địa chỉ" for="a-address" class="sm:col-span-2" :error="addressError?.field('address')"><Input id="a-address" v-model="addressForm.address" required data-testid="a-address" /></FormField>
            </form>
            <template #footer>
                <Button variant="outline" @click="addressDialog = false">Hủy</Button>
                <Button type="submit" form="address-form" :loading="addressSaving" data-testid="a-save">Lưu</Button>
            </template>
        </Dialog>
    </template>
</template>
