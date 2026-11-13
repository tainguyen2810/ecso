<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ExternalLink, FileText, Pencil, RefreshCw } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { canIssueAgain, currentInvoice, type EInvoiceBuyer, type OrderEInvoice } from '../connections/einvoice';

/**
 * "Hóa đơn điện tử" on the order: who the invoice goes to (from the customer, editable before issuing), "Xuất hóa đơn",
 * then the number, date and PDF once the provider has issued it. One invoice per order; a failed try can be resent.
 */
const props = defineProps<{ orderId: string; orderStatus: string }>();
const tenant = useTenantStore();
const toast = useToast();

const data = ref<OrderEInvoice | null>(null);
const error = ref<string | null>(null);
const busy = ref<'issue' | 'refresh' | null>(null);
const editing = ref(false);
const buyer = reactive<EInvoiceBuyer>({ name: '', legal_name: null, tax_code: null, address: null, email: null, phone: null });
const errors = ref<Record<string, string | undefined>>({});

async function load() {
    try {
        data.value = (await api.get(`/orders/${props.orderId}/einvoice`)).data.data;
        Object.assign(buyer, data.value!.buyer);
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch(() => props.orderStatus, load);

const invoice = computed(() => currentInvoice(data.value?.invoices ?? []));
const canIssue = computed(() => tenant.can('einvoice.issue') && data.value?.ready && canIssueAgain(data.value.invoices) && props.orderStatus !== 'CANCELLED');
const statusBadge = computed(() => {
    const s = invoice.value?.status;
    if (s === 'issued') return { tone: 'success' as const, text: 'Đã xuất' };
    if (s === 'pending') return { tone: 'warning' as const, text: 'Đang xuất' };
    if (s === 'failed') return { tone: 'danger' as const, text: 'Lỗi' };
    return { tone: 'muted' as const, text: 'Chưa xuất' };
});
const shown = computed(() => (invoice.value && invoice.value.status !== 'failed' ? (invoice.value.buyer ?? buyer) : buyer));

async function issue() {
    busy.value = 'issue';
    errors.value = {};
    try {
        const body = editing.value ? { buyer: { ...buyer, legal_name: buyer.tax_code ? buyer.legal_name || buyer.name : buyer.legal_name } } : {};
        const { data: res } = await api.post(`/orders/${props.orderId}/einvoice`, body);
        editing.value = false;
        if (res.data.status === 'issued') toast.success(`Đã xuất hóa đơn số ${res.data.invoice_number}`);
        else if (res.data.status === 'failed') toast.error(res.data.message ?? 'Nhà cung cấp từ chối hóa đơn');
        else toast.success('Đã gửi sang nhà cung cấp, đang chờ cấp số');
        await load();
    } catch (e) {
        const err = e as ApiError;
        errors.value = { name: err.field('buyer.name'), tax_code: err.field('buyer.tax_code'), email: err.field('buyer.email') };
        if (!Object.values(errors.value).some(Boolean)) toast.error(err.field('einvoice') ?? err.message);
    } finally {
        busy.value = null;
    }
}

async function refresh() {
    if (!invoice.value) return;
    busy.value = 'refresh';
    try {
        await api.post(`/einvoices/${invoice.value.id}/refresh`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Card caps title="Hóa đơn điện tử" data-testid="einvoice-card">
        <template #actions><Badge :tone="statusBadge.tone" data-testid="einvoice-state">{{ statusBadge.text }}</Badge></template>
        <p v-if="error" class="px-5 py-4 text-sm text-destructive">{{ error }}</p>
        <div v-else-if="data" class="space-y-3 px-5 py-4 text-sm">
            <p v-if="!data.ready && !invoice" class="text-muted-foreground">
                Chưa kết nối hóa đơn điện tử.
                <RouterLink v-if="tenant.can('settings.manage')" to="/settings/connections" class="text-primary hover:underline">Kết nối</RouterLink>
            </p>

            <template v-else>
                <form v-if="editing" class="space-y-2" data-testid="einvoice-buyer-form" @submit.prevent="issue">
                    <label class="block space-y-1"><span class="text-xs text-muted-foreground">Tên người mua / đơn vị</span><Input v-model="buyer.name" data-testid="einvoice-buyer-name" /></label>
                    <p v-if="errors.name" class="text-xs text-destructive">{{ errors.name }}</p>
                    <label class="block space-y-1"><span class="text-xs text-muted-foreground">Mã số thuế (bỏ trống nếu là cá nhân)</span><Input v-model="buyer.tax_code" data-testid="einvoice-buyer-tax" /></label>
                    <p v-if="errors.tax_code" class="text-xs text-destructive">{{ errors.tax_code }}</p>
                    <label class="block space-y-1"><span class="text-xs text-muted-foreground">Địa chỉ</span><Input v-model="buyer.address" /></label>
                    <label class="block space-y-1"><span class="text-xs text-muted-foreground">Email nhận hóa đơn</span><Input v-model="buyer.email" type="email" /></label>
                    <p v-if="errors.email" class="text-xs text-destructive">{{ errors.email }}</p>
                </form>
                <dl v-else class="grid grid-cols-[110px_1fr] gap-x-2 gap-y-1" data-testid="einvoice-buyer">
                    <dt class="text-muted-foreground">{{ shown.tax_code ? 'Tên đơn vị' : 'Người mua' }}</dt><dd class="font-medium">{{ shown.legal_name || shown.name }}</dd>
                    <template v-if="shown.tax_code"><dt class="text-muted-foreground">Mã số thuế</dt><dd class="tabular-nums">{{ shown.tax_code }}</dd></template>
                    <template v-if="shown.address"><dt class="text-muted-foreground">Địa chỉ</dt><dd>{{ shown.address }}</dd></template>
                    <template v-if="shown.email"><dt class="text-muted-foreground">Email nhận</dt><dd class="break-all">{{ shown.email }}</dd></template>
                    <template v-if="data.template && (!invoice || invoice.status === 'failed')"><dt class="text-muted-foreground">Mẫu · ký hiệu</dt><dd>{{ data.template }}</dd></template>
                </dl>

                <div v-if="invoice?.status === 'issued'" class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900" data-testid="einvoice-issued">
                    <div>Số hóa đơn <b class="tabular-nums">{{ invoice.invoice_number }}</b> · ký hiệu {{ invoice.invoice_series }}</div>
                    <div class="text-xs">Ngày {{ invoice.issued_at ? formatDateTime(invoice.issued_at) : '—' }} · {{ formatMoney(invoice.total) }}</div>
                </div>
                <Alert v-else-if="invoice?.status === 'pending'" tone="amber">Đã gửi sang nhà cung cấp, đang chờ cấp số hóa đơn. EzSell tự kiểm tra lại.</Alert>
                <Alert v-else-if="invoice?.status === 'failed'" tone="red" data-testid="einvoice-error">{{ invoice.message ?? 'Nhà cung cấp từ chối hóa đơn.' }} Sửa thông tin rồi xuất lại.</Alert>

                <div class="flex flex-wrap gap-2">
                    <template v-if="canIssue">
                        <Button v-if="!editing" size="sm" variant="outline" data-testid="einvoice-edit" @click="editing = true"><Pencil /> Sửa thông tin</Button>
                        <Button v-else size="sm" variant="outline" @click="editing = false; Object.assign(buyer, data.buyer)">Thôi</Button>
                        <Button size="sm" :loading="busy === 'issue'" :disabled="busy !== null" data-testid="einvoice-issue" @click="issue"><FileText /> {{ invoice?.status === 'failed' ? 'Xuất lại' : 'Xuất hóa đơn' }}</Button>
                    </template>
                    <Button v-if="invoice?.status === 'pending'" size="sm" variant="outline" :loading="busy === 'refresh'" data-testid="einvoice-refresh" @click="refresh"><RefreshCw /> Kiểm tra lại</Button>
                    <a v-if="invoice?.status === 'issued' && invoice.pdf_url" :href="invoice.pdf_url" target="_blank" rel="noopener" class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-sm hover:bg-muted" data-testid="einvoice-pdf"><ExternalLink class="size-4" /> Xem PDF</a>
                </div>
                <p v-if="invoice?.status === 'issued' && ['RETURNED', 'CANCELLED'].includes(orderStatus)" class="text-xs text-amber-700">Đơn đã trả hoặc hủy sau khi xuất: lập hóa đơn điều chỉnh/thay thế trên trang nhà cung cấp.</p>
            </template>
        </div>
    </Card>
</template>
