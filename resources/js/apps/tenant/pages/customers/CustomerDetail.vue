<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, MapPin, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import CustomerDialog from './CustomerDialog.vue';
import { customerStatus, genderLabels, priceTierLabels } from './labels';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { orderStatus, paymentMethodNames, paymentMethods, paymentStatus } from '../orders/labels';
import type { Customer, CustomerAddress, CustomerLedgerEntry, CustomerNote, OrderPaymentData, OrderSummary } from '@/shared/types';

type Detail = Customer & { orders: OrderSummary[]; payments: OrderPaymentData[]; addresses: CustomerAddress[]; notes: CustomerNote[] };

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('customers.manage'));
const customer = ref<Detail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const dialog = ref(false);
const tab = ref(typeof route.query.tab === 'string' ? route.query.tab : 'orders');
watch(tab, (t) => router.replace({ query: t === 'orders' ? {} : { tab: t } }));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/customers/${route.params.id}`);
        customer.value = data.data;
        if (tab.value === 'debt') loadLedger();
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
watch(tab, (t) => t === 'debt' && ledger.value === null && loadLedger());

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
    { value: 'orders', label: 'Đơn hàng', count: customer.value?.orders_count ?? null },
    { value: 'debt', label: 'Công nợ' },
    { value: 'payments', label: 'Thu / hoàn tiền' },
    { value: 'addresses', label: 'Địa chỉ', count: (customer.value?.addresses.length ?? 0) + (customer.value?.address ? 1 : 0) },
    { value: 'notes', label: 'Ghi chú', count: customer.value?.notes.length ?? null },
]);
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
            <Button v-if="canManage && !customer.orders_count" variant="ghost" title="Xóa" @click="remove"><Trash2 /></Button>
        </PageHeader>

        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Số đơn</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatNumber(customer.orders_count) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng chi tiêu (không tính đơn hủy/hoàn)</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(customer.total_spent) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Còn nợ</p><p class="mt-1 text-2xl font-semibold tabular-nums" :class="Number(customer.debt) > 0 && 'text-destructive'" data-testid="customer-debt">{{ formatMoney(customer.debt) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Mua gần nhất</p><p class="mt-1 text-lg font-semibold">{{ customer.last_order_at ? formatDate(customer.last_order_at) : '—' }}</p></div></Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <Tabs v-model="tab" :tabs="tabs" data-testid="customer-tabs" />

                <Card v-if="tab === 'orders'">
                    <table v-if="customer.orders.length" class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-4 py-2 font-medium">Mã đơn</th><th class="px-4 py-2 font-medium">Ngày</th><th class="px-4 py-2 font-medium">Trạng thái</th><th class="px-4 py-2 font-medium">Thanh toán</th><th class="px-4 py-2 text-right font-medium">Tổng</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="o in customer.orders" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/orders/${o.id}`)">
                                <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ o.external_order_id }}<div class="font-sans text-muted-foreground">{{ o.channel?.name }}</div></td>
                                <td class="px-4 py-2.5 text-muted-foreground">{{ formatDateTime(o.placed_at) }}</td>
                                <td class="px-4 py-2.5"><Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label }}</Badge></td>
                                <td class="px-4 py-2.5"><Badge v-if="o.payment_status" :tone="paymentStatus[o.payment_status]?.tone">{{ paymentStatus[o.payment_status]?.label }}</Badge></td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có đơn.</p>
                    <p v-if="customer.orders.length >= 50" class="border-t px-5 py-2.5 text-xs text-muted-foreground">Đang hiện 50 đơn gần nhất.</p>
                </Card>

                <Card v-else-if="tab === 'debt'" data-testid="customer-ledger">
                    <ErrorState v-if="ledgerError" :message="ledgerError" @retry="loadLedger" />
                    <div v-else-if="ledger === null" class="p-4"><Skeleton class="h-24" /></div>
                    <p v-else-if="!ledger.length" class="px-5 py-4 text-sm text-muted-foreground">Chưa phát sinh công nợ.</p>
                    <div v-else class="overflow-x-auto">
                        <p class="border-b px-5 py-2.5 text-xs text-muted-foreground">Mỗi đơn (trừ đơn hủy, đơn hoàn) cộng vào nợ, mỗi lần thu tiền trừ đi. Mới nhất ở trên.</p>
                        <table class="w-full text-sm">
                            <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr><th class="px-4 py-2 font-medium">Thời gian</th><th class="px-4 py-2 font-medium">Chứng từ</th><th class="px-4 py-2 font-medium">Diễn giải</th><th class="px-4 py-2 text-right font-medium">Phát sinh</th><th class="px-4 py-2 text-right font-medium">Còn nợ</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(l, i) in ledger" :key="i">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(l.at) }}</td>
                                    <td class="px-4 py-2.5 font-mono text-xs"><RouterLink v-if="l.order_id" :to="`/orders/${l.order_id}`" class="text-primary hover:underline">{{ l.ref }}</RouterLink></td>
                                    <td class="px-4 py-2.5">{{ l.note }}</td>
                                    <td :class="cn('px-4 py-2.5 text-right tabular-nums', Number(l.change) < 0 ? 'text-success' : '')">{{ Number(l.change) > 0 ? '+' : '' }}{{ formatMoney(l.change) }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(l.balance) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card v-else-if="tab === 'payments'">
                    <ul v-if="customer.payments.length" class="divide-y text-sm">
                        <li v-for="p in customer.payments" :key="p.id" class="flex items-center justify-between px-5 py-2.5" :class="p.voided_at && 'opacity-50 line-through'">
                            <div><div>{{ p.kind === 'refund' ? 'Hoàn' : 'Thu' }} · {{ paymentMethodNames[p.method] ?? p.method }}</div><div class="text-xs text-muted-foreground">{{ p.order?.number }} · {{ formatDateTime(p.paid_at) }}</div></div>
                            <span class="tabular-nums" :class="p.kind === 'refund' ? 'text-destructive' : 'text-success'">{{ p.kind === 'refund' ? '−' : '+' }}{{ formatMoney(p.amount) }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
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
                </Card>
            </div>
        </div>

        <CustomerDialog v-model:open="dialog" :customer="customer" @saved="load" />
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
