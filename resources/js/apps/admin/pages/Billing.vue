<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { AlertTriangle, ReceiptText, Search } from 'lucide-vue-next';
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
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { cycleLabels, invoiceKinds, invoiceStatus, paymentStatus, type Cycle } from '../../tenant/pages/billing/labels';

interface Summary {
    mrr: number;
    arr: number;
    paying_tenants: number;
    trialing: number;
    past_due: number;
    collected_30d: number;
    pending_invoices: number;
    pending_amount: number;
    by_plan: { key: string; name: string; tenants: number; mrr: number }[];
    bank_configured: boolean;
}
interface InvoiceRow {
    id: string;
    number: string;
    tenant: { id: string; name: string } | null;
    plan: string;
    billing_cycle: Cycle;
    kind: string;
    amount: string;
    credit: string;
    status: string;
    transfer_code: string;
    due_at: string | null;
    paid_at: string | null;
    created_at: string | null;
    payments: { reference: string; amount: string; status: string; received_at: string }[];
}

const toast = useToast();
const summary = ref<Summary | null>(null);
const rows = ref<InvoiceRow[] | null>(null);
const error = ref<string | null>(null);
const status = ref('pending');
const q = ref('');
let timer: ReturnType<typeof setTimeout>;

const confirmOpen = ref(false);
const selected = ref<InvoiceRow | null>(null);
const form = ref({ reference: '', amount: '' as string | number, received_at: '', note: '' });
const formError = ref<Record<string, string | undefined>>({});
const saving = ref(false);

async function load() {
    error.value = null;
    try {
        const [s, inv] = await Promise.all([
            api.get('/admin/billing/summary'),
            api.get('/admin/invoices', { params: { status: status.value || undefined, q: q.value || undefined } }),
        ]);
        summary.value = s.data.data;
        rows.value = inv.data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch(status, load);
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

function localNow(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

function openConfirm(row: InvoiceRow) {
    selected.value = row;
    form.value = { reference: '', amount: Number(row.amount), received_at: localNow(), note: '' };
    formError.value = {};
    confirmOpen.value = true;
}

async function submit() {
    if (!selected.value) return;
    saving.value = true;
    formError.value = {};
    try {
        const res = (
            await api.post(`/admin/invoices/${selected.value.id}/payments`, {
                ...form.value,
                received_at: new Date(form.value.received_at).toISOString(),
            })
        ).data.data;
        confirmOpen.value = false;
        if (res.duplicate) toast.success('Giao dịch này đã được ghi nhận trước đó — không ghi nhận lại.');
        else toast.success(`Đã ghi nhận: ${paymentStatus[res.payment_status]?.label ?? res.payment_status}.`);
        await load();
    } catch (e) {
        const err = e as ApiError;
        formError.value = { reference: err.field('reference'), amount: err.field('amount'), received_at: err.field('received_at') };
        if (!Object.values(formError.value).some(Boolean)) toast.error(err.message);
    } finally {
        saving.value = false;
    }
}

async function cancel(row: InvoiceRow) {
    try {
        await api.post(`/admin/invoices/${row.id}/cancel`);
        toast.success(`Đã hủy ${row.number}.`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div class="mb-6">
        <h1 class="text-xl font-semibold">Hóa đơn & doanh thu</h1>
        <p class="text-sm text-muted-foreground">Doanh thu thuê bao của nền tảng. Thanh toán chuyển khoản được xác nhận tại đây sau khi đối chiếu sao kê.</p>
    </div>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <template v-else>
        <div v-if="summary && !summary.bank_configured" class="mb-4 flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
            <AlertTriangle class="mt-0.5 size-4" />
            Chưa cấu hình tài khoản nhận tiền (BILLING_BANK_* trong .env). Khách hàng sẽ không thấy thông tin chuyển khoản.
        </div>

        <div v-if="!summary" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="billing-summary">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">MRR</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatMoney(summary.mrr) }}</p>
                <p class="text-xs text-muted-foreground">ARR {{ formatMoney(summary.arr) }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Tenant trả phí</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ summary.paying_tenants }}</p>
                <p class="text-xs text-muted-foreground">{{ summary.trialing }} đang dùng thử · {{ summary.past_due }} quá hạn</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Đã thu 30 ngày</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatMoney(summary.collected_30d) }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Chờ thanh toán</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatMoney(summary.pending_amount) }}</p>
                <p class="text-xs text-muted-foreground">{{ summary.pending_invoices }} hóa đơn</p>
            </Card>
        </div>

        <Card v-if="summary" class="mt-6" title="Theo gói">
            <table class="w-full text-sm">
                <thead class="border-b text-left text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Gói</th>
                        <th class="px-5 py-2.5 text-right font-medium">Tenant</th>
                        <th class="px-5 py-2.5 text-right font-medium">MRR</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="p in summary.by_plan" :key="p.key">
                        <td class="px-5 py-2.5">{{ p.name }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ p.tenants }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatMoney(p.mrr) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card class="mt-6" title="Hóa đơn" data-testid="admin-invoices">
            <template #actions>
                <div class="flex gap-2">
                    <div class="relative w-56">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="q" placeholder="Số HĐ, nội dung CK, tenant" class="pl-8" data-testid="invoice-search" />
                    </div>
                    <Select
                        v-model="status"
                        class="w-40"
                        :options="[{ value: 'pending', label: 'Chờ thanh toán' }, { value: 'paid', label: 'Đã thanh toán' }, { value: 'cancelled', label: 'Đã hủy' }, { value: '', label: 'Tất cả' }]"
                    />
                </div>
            </template>
            <div v-if="!rows" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-9" /></div>
            <EmptyState v-else-if="!rows.length" :icon="ReceiptText" title="Không có hóa đơn" />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Số / nội dung CK</th>
                            <th class="px-4 py-2.5 font-medium">Tenant</th>
                            <th class="px-4 py-2.5 font-medium">Gói</th>
                            <th class="px-4 py-2.5 text-right font-medium">Số tiền</th>
                            <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                            <th class="px-4 py-2.5 font-medium">Hạn / đã trả</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in rows" :key="r.id" class="align-top">
                            <td class="px-4 py-2.5">
                                <p class="font-mono text-xs">{{ r.number }}</p>
                                <p class="font-mono text-xs font-semibold">{{ r.transfer_code }}</p>
                            </td>
                            <td class="px-4 py-2.5">{{ r.tenant?.name }}</td>
                            <td class="px-4 py-2.5">
                                {{ invoiceKinds[r.kind] ?? r.kind }} {{ r.plan }}
                                <p class="text-xs text-muted-foreground">{{ cycleLabels[r.billing_cycle] }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney(r.amount) }}</td>
                            <td class="px-4 py-2.5">
                                <Badge :tone="invoiceStatus[r.status]?.tone ?? 'muted'">{{ invoiceStatus[r.status]?.label ?? r.status }}</Badge>
                                <p v-for="p in r.payments" :key="p.reference" class="mt-1 text-xs text-muted-foreground">
                                    {{ p.reference }}: {{ formatMoney(p.amount) }} · {{ paymentStatus[p.status]?.label ?? p.status }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ formatDateTime(r.paid_at ?? r.due_at) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div v-if="r.status === 'pending'" class="flex justify-end gap-1">
                                    <Button size="sm" data-testid="confirm-payment" @click="openConfirm(r)">Xác nhận đã nhận tiền</Button>
                                    <Button size="sm" variant="ghost" @click="cancel(r)">Hủy</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>

    <Dialog
        v-model:open="confirmOpen"
        title="Xác nhận chuyển khoản"
        :description="selected ? `${selected.number} · ${selected.tenant?.name} · nội dung ${selected.transfer_code}` : ''"
    >
        <form class="space-y-4" data-testid="payment-form" @submit.prevent="submit">
            <FormField label="Mã giao dịch ngân hàng" for="pay-ref" :error="formError.reference" hint="Lấy từ sao kê. Cùng một mã chỉ được ghi nhận một lần.">
                <Input id="pay-ref" v-model="form.reference" :invalid="!!formError.reference" required data-testid="payment-reference" />
            </FormField>
            <FormField label="Số tiền nhận được (VND)" for="pay-amount" :error="formError.amount" hint="Nhập đúng số tiền trên sao kê. Thiếu tiền thì hóa đơn vẫn chờ thanh toán.">
                <Input id="pay-amount" v-model="form.amount" type="number" min="1" :invalid="!!formError.amount" required data-testid="payment-amount" />
            </FormField>
            <FormField label="Thời điểm nhận" for="pay-at" :error="formError.received_at">
                <Input id="pay-at" v-model="form.received_at" type="datetime-local" :invalid="!!formError.received_at" required />
            </FormField>
            <FormField label="Ghi chú" for="pay-note">
                <Input id="pay-note" v-model="form.note" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="confirmOpen = false">Đóng</Button>
            <Button :loading="saving" data-testid="submit-payment" @click="submit">Ghi nhận thanh toán</Button>
        </template>
    </Dialog>
</template>
