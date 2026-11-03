<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, CircleCheck, PackageCheck, Pencil, Printer, Send, Tags, Undo2, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SupplierPaymentDialog from './SupplierPaymentDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { owed, poStatus, supplierPaymentMethods } from './labels';
import { printPurchaseOrder } from './printOrder';
import type { PurchaseOrderData } from '@/shared/types';

const route = useRoute();
const toast = useToast();
const tenant = useTenantStore();
const po = ref<PurchaseOrderData | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        po.value = (await api.get(`/purchase-orders/${route.params.id}`)).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const isOpen = computed(() => po.value && ['draft', 'ordered', 'partial'].includes(po.value.status));
const due = computed(() => (po.value ? owed(po.value) : 0));
const returnable = computed(() => (po.value?.lines ?? []).filter((l) => l.received_qty - l.returned_qty > 0));

async function act(action: 'place' | 'close' | 'cancel', reason?: string) {
    busy.value = true;
    try {
        po.value = (await api.post(`/purchase-orders/${po.value!.id}/actions/${action}`, { reason: reason ?? null })).data.data;
        toast.success({ place: 'Đã đặt hàng.', close: 'Đã đóng đơn, không chờ phần còn lại.', cancel: 'Đã hủy đơn nhập.' }[action]);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

// Receive goods
const receiveOpen = ref(false);
const receiveQty = ref<Record<string, string>>({});
const receiveCost = ref<Record<string, string>>({});
const receiveNote = ref('');
const receiveError = ref<ApiError | null>(null);
function openReceive() {
    receiveError.value = null;
    receiveNote.value = '';
    receiveQty.value = Object.fromEntries((po.value?.lines ?? []).map((l) => [l.variant!.id, String(l.remaining)]));
    receiveCost.value = Object.fromEntries((po.value?.lines ?? []).map((l) => [l.variant!.id, String(Math.round(Number(l.unit_cost)))]));
    receiveOpen.value = true;
}
// Goods + VAT of this delivery (what it adds to the supplier debt, before any freight on the supplier invoice).
const receiveTotal = computed(() =>
    (po.value?.lines ?? []).reduce((t, l) => t + (Number(receiveQty.value[l.variant!.id]) || 0) * (Number(receiveCost.value[l.variant!.id]) || 0) * (1 + Number(l.tax_rate) / 100), 0),
);
function print() {
    if (po.value) printPurchaseOrder(po.value, { name: tenant.current?.name ?? '' });
}
async function receive() {
    busy.value = true;
    receiveError.value = null;
    try {
        const canPrice = tenant.can('purchasing.manage');
        const lines = (po.value?.lines ?? [])
            .filter((l) => Number(receiveQty.value[l.variant!.id]) > 0)
            .map((l) => ({ variant_id: l.variant!.id, quantity: Number(receiveQty.value[l.variant!.id]), ...(canPrice ? { unit_cost: Number(receiveCost.value[l.variant!.id]) } : {}) }));
        const { data } = await api.post(`/purchase-orders/${po.value!.id}/receive`, { lines, note: receiveNote.value || null });
        po.value = data.data;
        receiveOpen.value = false;
        toast.success(`Đã nhập kho phiếu ${data.receipt.number}.`);
    } catch (e) {
        receiveError.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

// Return to supplier
const returnOpen = ref(false);
const returnQty = ref<Record<string, string>>({});
const returnReason = ref('');
const returnError = ref<ApiError | null>(null);
function openReturn() {
    returnError.value = null;
    returnReason.value = '';
    returnQty.value = Object.fromEntries(returnable.value.map((l) => [l.variant!.id, '']));
    returnOpen.value = true;
}
async function submitReturn() {
    busy.value = true;
    returnError.value = null;
    try {
        const lines = returnable.value.filter((l) => Number(returnQty.value[l.variant!.id]) > 0).map((l) => ({ variant_id: l.variant!.id, quantity: Number(returnQty.value[l.variant!.id]) }));
        const { data } = await api.post('/supplier-returns', { purchase_order_id: po.value!.id, reason: returnReason.value || null, lines });
        returnOpen.value = false;
        toast.success(`Đã xuất trả ${data.data.number} · ${formatMoney(data.data.total)}.`);
        await load();
    } catch (e) {
        returnError.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

// Payments
const payOpen = ref(false);
async function voidPayment(id: string) {
    try {
        await api.post(`/supplier-payments/${id}/void`, { reason: 'Hủy từ màn hình đơn nhập' });
        toast.success('Đã hủy phiếu chi.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div v-if="loading && !po" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="po">
        <RouterLink to="/purchasing" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Đơn nhập hàng</RouterLink>
        <PageHeader :title="po.number" :description="`${po.supplier?.name} → ${po.warehouse?.name}${po.expected_at ? ' · dự kiến về ' + formatDate(po.expected_at) : ''}`">
            <Badge :tone="poStatus[po.status]?.tone" class="self-center" data-testid="po-status">{{ poStatus[po.status]?.label }}</Badge>
            <RouterLink
                v-if="(po.lines ?? []).some((l) => l.variant && l.received_qty > 0)"
                :to="{ path: '/products/labels', query: { lines: (po.lines ?? []).filter((l) => l.variant && l.received_qty > 0).map((l) => `${l.variant!.id}:${l.received_qty}`).join(',') } }"
            >
                <Button variant="outline" data-testid="po-labels"><Tags /> In tem hàng đã nhận</Button>
            </RouterLink>
            <Button variant="outline" data-testid="po-print" @click="print"><Printer /> In phiếu đặt hàng</Button>
            <Button v-if="po.status === 'draft' && tenant.can('purchasing.manage')" :loading="busy" @click="act('place')"><Send /> Đặt hàng</Button>
            <Button v-if="isOpen && (tenant.can('purchasing.manage') || tenant.can('inventory.receive'))" data-testid="po-receive" @click="openReceive"><PackageCheck /> Nhận hàng</Button>
            <Button v-if="tenant.can('purchasing.payment') && po.status !== 'cancelled' && due > 0" variant="outline" data-testid="po-pay" @click="payOpen = true"><Wallet /> Chi tiền</Button>
            <Button v-if="tenant.can('purchasing.manage') && returnable.length" variant="outline" data-testid="po-return" @click="openReturn"><Undo2 /> Trả hàng</Button>
            <Button v-if="po.editable && tenant.can('purchasing.manage')" variant="outline" @click="$router.push(`/purchasing/${po.id}/edit`)"><Pencil /> Sửa</Button>
            <Button v-if="po.status === 'partial' && tenant.can('purchasing.manage')" variant="ghost" :loading="busy" title="Không chờ phần còn lại" @click="act('close', 'Không chờ phần còn lại')"><CircleCheck /> Đóng đơn</Button>
            <Button v-if="['draft', 'ordered'].includes(po.status) && tenant.can('purchasing.manage')" variant="ghost" :loading="busy" @click="act('cancel')"><Ban /> Hủy</Button>
        </PageHeader>

        <div class="mb-4 grid gap-4 sm:grid-cols-4">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Giá trị đặt</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ formatMoney(po.total) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Đã nhận ({{ formatNumber(po.received_quantity) }}/{{ formatNumber(po.total_quantity) }})</p><p class="mt-1 text-xl font-semibold tabular-nums" data-testid="po-received-value">{{ formatMoney(po.received_value) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Đã trả hàng / đã chi</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ formatMoney(po.returned_value) }} / {{ formatMoney(po.paid_amount) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Còn nợ NCC</p><p class="mt-1 text-xl font-semibold tabular-nums" :class="due > 0 && 'text-destructive'" data-testid="po-due">{{ formatMoney(due) }}</p></div></Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Hàng đặt" class="lg:col-span-2">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-4 py-2 font-medium">SKU</th><th class="px-4 py-2 text-right font-medium">Đặt</th><th class="px-4 py-2 text-right font-medium">Đã nhận</th><th class="px-4 py-2 text-right font-medium">Đã trả</th><th class="px-4 py-2 text-right font-medium">Còn chờ</th><th class="px-4 py-2 text-right font-medium">Đơn giá</th><th class="px-4 py-2 text-right font-medium">VAT</th><th class="px-4 py-2 text-right font-medium" title="Giá vốn mỗi đơn vị khi nhập kho theo giá đặt">Giá vốn</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in po.lines" :key="l.variant?.id">
                                <td class="px-4 py-2.5"><p class="font-mono text-xs font-medium">{{ l.variant?.sku }}</p><p class="max-w-64 truncate text-xs text-muted-foreground">{{ l.variant?.name }}</p></td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(l.quantity) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums" :class="l.received_qty >= l.quantity && 'text-success'">{{ formatNumber(l.received_qty) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ l.returned_qty ? formatNumber(l.returned_qty) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums" :class="l.remaining > 0 && isOpen && 'font-medium text-amber-600'">{{ l.remaining ? formatNumber(l.remaining) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(l.unit_cost) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ Number(l.tax_rate) }}%</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatMoney(l.landed_unit_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 border-t px-5 py-3 text-sm sm:ml-auto sm:w-96" data-testid="po-cost-summary">
                    <dt class="text-muted-foreground">Tiền hàng</dt><dd class="text-right tabular-nums">{{ formatMoney(po.subtotal) }}</dd>
                    <dt class="text-muted-foreground">Thuế GTGT{{ po.vat_in_cost ? ' (tính vào giá vốn)' : ' (được khấu trừ)' }}</dt><dd class="text-right tabular-nums">{{ formatMoney(po.tax_amount) }}</dd>
                    <template v-if="Number(po.extra_cost)">
                        <dt class="text-muted-foreground">Chi phí nhập{{ po.extra_cost_note ? ` · ${po.extra_cost_note}` : '' }} ({{ po.extra_cost_to_supplier ? 'trả NCC' : 'trả bên khác' }})</dt><dd class="text-right tabular-nums">{{ formatMoney(po.extra_cost) }}</dd>
                    </template>
                    <dt class="font-medium">Phải trả NCC</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(po.total) }}</dd>
                    <template v-if="Number(po.received_tax) || Number(po.received_extra_cost)">
                        <dt class="pt-1 text-xs text-muted-foreground">Đã nhận: VAT đầu vào / chi phí đã phân bổ</dt><dd class="pt-1 text-right text-xs tabular-nums text-muted-foreground">{{ formatMoney(po.received_tax) }} / {{ formatMoney(po.received_extra_cost) }}</dd>
                    </template>
                </dl>
                <p v-if="po.note" class="border-t px-5 py-3 text-sm text-muted-foreground">Ghi chú: {{ po.note }}</p>
            </Card>
            <div class="space-y-4">
                <Card title="Các đợt nhận hàng">
                    <ul v-if="po.receipts?.length" class="divide-y text-sm">
                        <li v-for="r in po.receipts" :key="r.id" class="flex items-center justify-between px-5 py-2.5">
                            <div><RouterLink :to="`/inventory/receipts/${r.id}`" class="font-mono text-xs font-medium hover:underline">{{ r.number }}</RouterLink><div class="text-xs text-muted-foreground">{{ formatDateTime(r.created_at) }} · {{ r.created_by }}</div></div>
                            <div class="text-right tabular-nums"><div>{{ formatNumber(r.total_quantity) }} sp</div><div class="text-xs text-muted-foreground">{{ formatMoney(r.total_value) }}</div></div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa nhận hàng.</p>
                </Card>
                <Card title="Phiếu chi">
                    <ul v-if="po.payments?.length" class="divide-y text-sm">
                        <li v-for="p in po.payments" :key="p.id" class="flex items-center justify-between gap-2 px-5 py-2.5" :class="p.voided_at && 'opacity-50'">
                            <div :class="p.voided_at && 'line-through'"><div>{{ supplierPaymentMethods[p.method] }}<span v-if="p.reference" class="text-muted-foreground"> · {{ p.reference }}</span></div><div class="text-xs text-muted-foreground">{{ formatDateTime(p.paid_at) }} · {{ p.created_by }}</div></div>
                            <div class="flex items-center gap-2"><span class="tabular-nums">{{ formatMoney(p.amount) }}</span><button v-if="!p.voided_at && tenant.can('purchasing.payment')" class="text-xs text-muted-foreground hover:text-destructive" @click="voidPayment(p.id)">Hủy</button></div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa chi tiền.</p>
                </Card>
                <Card v-if="po.returns?.length" title="Trả hàng nhà cung cấp">
                    <ul class="divide-y text-sm">
                        <li v-for="r in po.returns" :key="r.id" class="flex items-center justify-between px-5 py-2.5">
                            <div><span class="font-mono text-xs font-medium">{{ r.number }}</span><div class="text-xs text-muted-foreground">{{ formatDateTime(r.created_at) }}<template v-if="r.reason"> · {{ r.reason }}</template></div></div>
                            <span class="tabular-nums text-destructive">−{{ formatMoney(r.total) }}</span>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <Dialog v-model:open="receiveOpen" title="Nhận hàng" description="Nhập số lượng thực nhận đợt này. Có thể nhận nhiều đợt. Tồn kho và giá vốn bình quân được cập nhật ngay.">
            <form id="receive-form" class="space-y-3" @submit.prevent="receive">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-muted-foreground"><tr><th class="py-1 font-medium">SKU</th><th class="py-1 text-right font-medium">Còn chờ</th><th class="py-1 text-right font-medium">Nhận</th><th v-if="tenant.can('purchasing.manage')" class="py-1 text-right font-medium">Giá nhập</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in po.lines" :key="l.variant?.id">
                            <td class="py-2"><span class="font-mono text-xs">{{ l.variant?.sku }}</span><p v-if="receiveError?.field(`lines.${i}.quantity`)" class="text-xs text-destructive">{{ receiveError.field(`lines.${i}.quantity`) }}</p></td>
                            <td class="py-2 text-right tabular-nums text-muted-foreground">{{ formatNumber(l.remaining) }}</td>
                            <td class="py-2 text-right"><input v-model="receiveQty[l.variant!.id]" type="number" min="0" :max="l.remaining" :disabled="!l.remaining" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" :data-testid="`rcv-qty-${i}`" /></td>
                            <td v-if="tenant.can('purchasing.manage')" class="py-2 text-right"><input v-model="receiveCost[l.variant!.id]" type="number" min="0" :disabled="!l.remaining" class="h-8 w-28 rounded-md border border-input bg-card px-2 text-right tabular-nums" :data-testid="`rcv-cost-${i}`" /></td>
                        </tr>
                    </tbody>
                </table>
                <FormField label="Ghi chú phiếu nhập"><Input v-model="receiveNote" placeholder="Số hóa đơn NCC, người giao…" /></FormField>
                <p class="text-right text-sm">Giá trị đợt này (gồm VAT): <span class="font-semibold tabular-nums">{{ formatMoney(receiveTotal) }}</span></p>
                <p v-if="receiveError && (receiveError.field('lines') || !receiveError.body.errors)" class="text-sm text-destructive">{{ receiveError.field('lines') ?? receiveError.message }}</p>
            </form>
            <template #footer>
                <Button variant="outline" @click="receiveOpen = false">Hủy</Button>
                <Button type="submit" form="receive-form" :loading="busy" data-testid="rcv-save">Nhập kho</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="returnOpen" title="Trả hàng nhà cung cấp" description="Hàng xuất khỏi kho ngay và công nợ NCC giảm theo giá nhập của đơn.">
            <form id="return-form" class="space-y-3" @submit.prevent="submitReturn">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-muted-foreground"><tr><th class="py-1 font-medium">SKU</th><th class="py-1 text-right font-medium">Có thể trả</th><th class="py-1 text-right font-medium">Trả</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in returnable" :key="l.variant?.id">
                            <td class="py-2"><span class="font-mono text-xs">{{ l.variant?.sku }}</span><p v-if="returnError?.field(`lines.${i}.quantity`)" class="text-xs text-destructive">{{ returnError.field(`lines.${i}.quantity`) }}</p></td>
                            <td class="py-2 text-right tabular-nums text-muted-foreground">{{ formatNumber(l.received_qty - l.returned_qty) }}</td>
                            <td class="py-2 text-right"><input v-model="returnQty[l.variant!.id]" type="number" min="0" :max="l.received_qty - l.returned_qty" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" :data-testid="`ret-qty-${i}`" /></td>
                        </tr>
                    </tbody>
                </table>
                <FormField label="Lý do"><Input v-model="returnReason" placeholder="Hàng lỗi, sai mẫu…" data-testid="ret-reason" /></FormField>
                <p v-if="returnError && !returnError.body.errors?.['lines.0.quantity']" class="text-sm text-destructive">{{ returnError.message }}</p>
            </form>
            <template #footer>
                <Button variant="outline" @click="returnOpen = false">Hủy</Button>
                <Button type="submit" form="return-form" :loading="busy" data-testid="ret-save">Xuất trả</Button>
            </template>
        </Dialog>

        <SupplierPaymentDialog v-if="po.supplier" v-model:open="payOpen" :supplier-id="po.supplier.id" :order="{ id: po.id, number: po.number }" :suggested="due" @saved="load" />
    </template>
</template>
