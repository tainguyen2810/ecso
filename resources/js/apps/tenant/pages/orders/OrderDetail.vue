<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { AlertTriangle, ArrowLeft, Ban, Banknote, Check, Clock, Copy, Link2, MapPin, MessageSquare, Pencil, Printer, ReceiptText, RefreshCw, Repeat, Truck, Undo2, UserRound, Wallet, Warehouse, X } from 'lucide-vue-next';
import TagInput from '../../components/TagInput.vue';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, initials } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { actionLabels, capabilityLabels, deadlineLabel, historySource, manualActionOrder, mappingStatus, marketplaceNames, orderStatus, paymentMethodNames, paymentMethods, paymentStatus, returnStatus, returnTypes } from './labels';
import PrintDialog from './PrintDialog.vue';
import { downloadPackingSlips } from './packingSlip';
import { orderWorkflow } from './workflow';
import { useSettingsStore } from '@/shared/stores/settings';
import type { OrderDetail, Ref } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();

const order = ref<OrderDetail | null>(null);
const printOpen = ref(false);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const assignees = ref<Ref[]>([]);
const note = ref('');

const cancelOpen = ref(false);
const cancelReason = ref('');
// Reasons set up in Cấu hình → Cấu hình bán hàng, offered as one-click choices.
const settingsStore = useSettingsStore();
settingsStore.load().catch(() => {});
const cancelReasons = computed(() => (settingsStore.data?.reasons ?? []).filter((r) => r.cancel).map((r) => r.label));
const returnReasons = computed(() => (settingsStore.data?.reasons ?? []).filter((r) => r.return).map((r) => r.label));
const returnOpen = ref(false);
const returnForm = ref({ type: 'return', reason: '', refund_amount: '', lines: {} as Record<number, number> });
const returnErrors = ref<ApiError | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/orders/${route.params.id}`);
        order.value = data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    await load();
    // From "Tạo đơn trả hàng" on the returns list: open the return form straight away.
    if (route.query.return === '1' && order.value && tenant.can('returns.inspect')) openReturn();
    api.get('/orders/assignees').then(({ data }) => (assignees.value = data.data)).catch(() => {});
    api.get('/orders/tags').then(({ data }) => (tagSuggestions.value = data.data)).catch(() => {});
});

// Tags and the promised delivery date are saved as soon as they change.
const meta = ref({ tags: [] as string[], deliver_on: '' });
const tagSuggestions = ref<string[]>([]);
watch(order, (o) => (meta.value = { tags: [...(o?.tags ?? [])], deliver_on: o?.deliver_on ?? '' }));
async function saveMeta() {
    try {
        const { data } = await api.put(`/orders/${order.value!.id}/meta`, { tags: meta.value.tags, deliver_on: meta.value.deliver_on || null });
        order.value = { ...order.value!, tags: data.data.tags, deliver_on: data.data.deliver_on };
        toast.success('Đã lưu.');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

const isManual = computed(() => order.value?.source === 'manual');
const actionList = computed(() => {
    const entries = Object.entries(order.value?.actions ?? {}).filter(([, a]) => a.allowed);
    return isManual.value ? entries.sort(([a], [b]) => manualActionOrder.indexOf(a) - manualActionOrder.indexOf(b)) : entries;
});

const handoverOpen = ref(false);
const handover = ref({ carrier: '', tracking_number: '' });
const payOpen = ref(false);
const payForm = ref({ kind: 'payment', method: 'cash', amount: '' as string | number, reference: '', note: '' });
const payError = ref<ApiError | null>(null);

function startAction(action: string) {
    if (action === 'cancel') cancelOpen.value = true;
    else if (action === 'handover' && isManual.value) {
        handover.value = { carrier: order.value!.carrier ?? '', tracking_number: order.value!.tracking_number ?? '' };
        handoverOpen.value = true;
    } else act(action);
}

function openPay(kind: 'payment' | 'refund') {
    const o = order.value!;
    const refundable = Number(o.paid_amount ?? 0) - Number(o.refunded_amount ?? 0);
    payError.value = null;
    payForm.value = { kind, method: o.is_cod && kind === 'payment' ? 'cod' : 'cash', amount: kind === 'payment' ? Number(o.balance_due ?? 0) : refundable, reference: '', note: '' };
    payOpen.value = true;
}

async function pay() {
    busy.value = 'pay';
    payError.value = null;
    try {
        const f = payForm.value;
        await api.post(`/orders/${order.value!.id}/payments`, { ...f, amount: Number(f.amount), reference: f.reference || null, note: f.note || null });
        toast.success(f.kind === 'refund' ? 'Đã ghi nhận hoàn tiền.' : 'Đã ghi nhận thanh toán.');
        payOpen.value = false;
        await load();
    } catch (e) {
        payError.value = e as ApiError;
    } finally {
        busy.value = null;
    }
}

async function voidPayment(id: string) {
    const reason = window.prompt('Lý do hủy phiếu thu/chi này?');
    if (!reason) return;
    try {
        await api.post(`/payments/${id}/void`, { reason });
        toast.success('Đã hủy phiếu.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
const workflow = computed(() => (order.value ? orderWorkflow(order.value) : []));
/** Step the order is waiting on (highlighted in the stepper); none once it stopped or finished. */
const currentStep = computed(() => (workflow.value.some((s) => s.state === 'stopped') ? -1 : workflow.value.findIndex((s) => s.state === 'todo')));
const totalQty = computed(() => order.value?.items.reduce((s, i) => s + i.quantity, 0) ?? 0);

async function act(action: string, params: Record<string, unknown> = {}) {
    busy.value = action;
    try {
        await api.post(isManual.value ? `/sales-orders/${order.value!.id}/actions/${action}` : `/orders/${order.value!.id}/actions/${action}`, params);
        toast.success(isManual.value ? `${actionLabels[action]}: đã cập nhật đơn.` : `${actionLabels[action]}: đã gửi lên sàn và cập nhật đơn.`);
        cancelOpen.value = false;
        handoverOpen.value = false;
        await load();
    } catch (e) {
        const err = e as ApiError;
        toast.error(err.body.code === 'UNSUPPORTED_CAPABILITY' ? `Sàn chưa hỗ trợ thao tác này qua API (${err.message}). Hãy thực hiện trên trang quản lý của sàn.` : (err.field('stock') ?? err.message));
    } finally {
        busy.value = null;
    }
}

async function sync() {
    busy.value = 'sync';
    try {
        const { data } = await api.post(`/orders/${order.value!.id}/sync`);
        toast.info(data.data.result === 'unchanged' ? 'Đơn đã khớp với sàn.' : 'Đã cập nhật đơn từ sàn.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function assign(userId: string | undefined) {
    try {
        await api.put(`/orders/${order.value!.id}/assignee`, { user_id: userId || null });
        toast.success('Đã cập nhật người xử lý.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function addNote() {
    if (!note.value.trim()) return;
    busy.value = 'note';
    try {
        const { data } = await api.post(`/orders/${order.value!.id}/notes`, { body: note.value });
        order.value!.notes.push(data.data);
        note.value = '';
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function print() {
    busy.value = 'print';
    try {
        const { data } = await api.post('/orders/packing-slips', { ids: [order.value!.id] });
        await downloadPackingSlips(data.data);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file PDF.');
    } finally {
        busy.value = null;
    }
}

function openReturn() {
    returnErrors.value = null;
    returnForm.value = { type: 'return', reason: '', refund_amount: '', lines: Object.fromEntries(order.value!.items.map((i) => [i.id, i.quantity])) };
    returnOpen.value = true;
}

async function createReturn() {
    busy.value = 'return';
    returnErrors.value = null;
    const f = returnForm.value;
    try {
        await api.post(`/orders/${order.value!.id}/returns`, {
            type: f.type,
            reason: f.reason || null,
            refund_amount: f.refund_amount === '' ? null : Number(f.refund_amount),
            lines: f.type === 'return' ? Object.entries(f.lines).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ order_item_id: Number(id), quantity: Number(q) })) : [],
        });
        toast.success('Đã tạo phiếu trả hàng.');
        returnOpen.value = false;
        await load();
    } catch (e) {
        returnErrors.value = e as ApiError;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div v-if="loading && !order" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="order">
        <RouterLink to="/orders" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Đơn hàng</RouterLink>

        <section class="mb-5 rounded-lg border bg-card shadow-xs">
            <div class="flex flex-wrap items-start gap-4 px-5 py-4">
                <MarketplaceLogo :marketplace="order.marketplace" :name="marketplaceNames[order.marketplace]" size="lg" class="size-11" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-mono text-xl font-semibold tracking-tight">Đơn {{ order.external_order_id }}</h1>
                        <Badge :tone="orderStatus[order.status]?.tone" data-testid="order-status">{{ orderStatus[order.status]?.label ?? order.status }}</Badge>
                        <Badge v-if="isManual && order.payment_status" :tone="paymentStatus[order.payment_status]?.tone" data-testid="payment-status">{{ paymentStatus[order.payment_status]?.label }}</Badge>
                        <span v-for="t in order.tags ?? []" :key="t" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">#{{ t }}</span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <template v-if="isManual">{{ order.channel?.name ?? 'Kênh riêng' }} · tạo lúc {{ formatDateTime(order.placed_at) }}<template v-if="order.created_by"> bởi {{ order.created_by }}</template></template>
                        <template v-else>{{ marketplaceNames[order.marketplace] ?? order.marketplace }} · {{ order.shop?.name ?? '' }} · đặt lúc {{ formatDateTime(order.placed_at) }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="!isManual" variant="outline" size="sm" :loading="busy === 'sync'" @click="sync"><RefreshCw /> Đồng bộ từ sàn</Button>
                    <Button v-if="isManual && order.editable && tenant.can('orders.create')" variant="outline" size="sm" data-testid="edit-order" @click="$router.push(`/orders/${order.id}/edit`)"><Pencil /> Sửa đơn</Button>
                    <Button v-if="isManual" variant="outline" size="sm" data-testid="print-invoice" @click="printOpen = true"><ReceiptText /> In đơn hàng</Button>
                    <Button v-if="tenant.can('orders.print')" variant="outline" size="sm" :loading="busy === 'print'" @click="print"><Printer /> In phiếu</Button>
                    <Button v-if="tenant.can('orders.create')" variant="outline" size="sm" data-testid="copy-order" @click="$router.push({ path: '/orders/new', query: { copy: order.id } })"><Copy /> Sao chép</Button>
                </div>
            </div>

            <ol class="grid grid-cols-5 border-t px-2 py-4 text-center text-xs" data-testid="order-workflow">
                <li v-for="(s, idx) in workflow" :key="idx" class="relative flex flex-col items-center gap-1.5 px-1">
                    <span v-if="idx > 0" :class="cn('absolute right-1/2 top-4 h-0.5 w-full -translate-y-1/2', s.state === 'done' ? 'bg-blue-500' : s.state === 'stopped' ? 'bg-rose-300' : 'bg-border')" />
                    <span
                        :class="
                            cn(
                                'relative z-10 flex size-8 items-center justify-center rounded-full border-2 bg-card text-xs font-semibold',
                                s.state === 'done' && 'border-blue-500 bg-blue-500 text-white',
                                s.state === 'stopped' && 'border-rose-500 bg-rose-500 text-white',
                                s.state === 'todo' && idx === currentStep && 'border-blue-500 text-blue-600 ring-4 ring-blue-100',
                                s.state === 'todo' && idx !== currentStep && 'border-border text-muted-foreground',
                            )
                        "
                    >
                        <Check v-if="s.state === 'done'" class="size-4" /><X v-else-if="s.state === 'stopped'" class="size-4" /><template v-else>{{ idx + 1 }}</template>
                    </span>
                    <span :class="cn('font-medium', s.state === 'todo' && idx !== currentStep && 'text-muted-foreground', idx === currentStep && 'text-blue-600', s.state === 'stopped' && 'text-rose-600')">{{ s.label }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ s.at ? formatDateTime(s.at) : '' }}</span>
                </li>
            </ol>
        </section>

        <div class="mb-5 space-y-3 empty:hidden">
            <Alert v-for="x in order.exchanged_from ?? []" :key="x.id" :icon="Repeat" tone="blue" data-testid="exchanged-from">
                Đơn đổi hàng từ phiếu trả <RouterLink :to="`/returns/${x.id}`" class="font-mono font-medium hover:underline">{{ x.number }}</RouterLink> của đơn <RouterLink :to="`/orders/${x.order.id}`" class="font-mono font-medium hover:underline">{{ x.order.external_order_id }}</RouterLink><template v-if="Number(x.credit) > 0">: hàng trả được trừ {{ formatMoney(x.credit) }} vào đơn này</template>.
            </Alert>
            <Alert v-if="order.sync_warning" :icon="AlertTriangle" tone="amber" data-testid="sync-warning">{{ order.sync_warning }}</Alert>
            <Alert v-if="order.flags.shortage" :icon="AlertTriangle" tone="red">
                <template v-if="['UNPAID', 'PENDING_CONFIRMATION', 'READY_TO_PACK', 'PACKED'].includes(order.status)">Đơn đang thiếu hàng: phần thiếu sẽ được giữ tự động khi có hàng nhập về (ưu tiên đơn cũ trước).</template>
                <template v-else>Sàn báo đã giao nhưng kho không đủ hàng cho một phần đơn: phần này chưa được trừ kho (không để âm kho). Hãy kiểm kê và lập phiếu điều chỉnh.</template>
            </Alert>
            <Alert v-if="order.flags.unmapped" :icon="Link2" tone="amber">
                Có sản phẩm chưa liên kết với SKU nội bộ nên chưa giữ tồn. <RouterLink :to="`/marketplaces/${order.shop?.id}/listings?status=unmapped`" class="font-medium text-primary underline">Liên kết SKU</RouterLink> — đơn sẽ tự cập nhật sau khi map.
            </Alert>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="min-w-0 space-y-5">
                <Card caps title="Sản phẩm" :description="`${order.items.length} dòng · ${totalQty} sản phẩm`">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="order-items">
                            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-5 py-2.5 font-medium">{{ isManual ? 'Sản phẩm' : 'Sản phẩm trên sàn' }}</th>
                                    <th class="px-4 py-2.5 font-medium">SKU nội bộ</th>
                                    <th class="px-4 py-2.5 text-right font-medium">SL</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Đơn giá</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Thành tiền</th>
                                    <th class="px-5 py-2.5 font-medium">Tồn kho</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="i in order.items" :key="i.id" class="align-top">
                                    <td class="px-5 py-3">
                                        <div class="font-medium">{{ i.name }}</div>
                                        <div class="text-xs text-muted-foreground">{{ i.variant_name }}<template v-if="!isManual"> · SKU sàn: {{ i.seller_sku ?? '—' }}</template><template v-else-if="Number(i.discount) > 0"> · giảm {{ formatMoney(i.discount) }}</template></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <RouterLink v-if="i.variant" :to="`/inventory/sku/${i.variant.id}`" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700 hover:text-primary">{{ i.variant.sku }}</RouterLink>
                                        <Badge v-else :tone="mappingStatus[i.mapping_status]?.tone">{{ mappingStatus[i.mapping_status]?.label }}</Badge>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium tabular-nums">{{ i.quantity }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(i.unit_price) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(i.quantity * Number(i.unit_price) - Number(i.discount)) }}</td>
                                    <td class="px-5 py-3 text-xs">
                                        <template v-if="i.reservation">
                                            <template v-if="i.reservation.status === 'consumed'">
                                                <span class="text-muted-foreground">Đã xuất {{ i.reservation.quantity }}</span>
                                                <span v-if="i.reservation.shortage" class="ml-1 font-medium text-rose-600">· {{ i.reservation.shortage }} giao khi kho không đủ</span>
                                            </template>
                                            <span v-else-if="i.reservation.status === 'released'" class="text-muted-foreground">Đã nhả giữ</span>
                                            <template v-else>
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">Giữ {{ i.reservation.quantity }}</span>
                                                <span v-if="i.reservation.shortage" class="ml-1 rounded-full bg-rose-50 px-2 py-0.5 font-medium text-rose-600">thiếu {{ i.reservation.shortage }}</span>
                                            </template>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end border-t bg-slate-50/60 px-5 py-4">
                        <dl class="grid w-full grid-cols-2 gap-y-1.5 text-sm sm:w-80">
                            <dt class="text-muted-foreground">Tiền hàng</dt><dd class="text-right tabular-nums">{{ formatMoney(order.subtotal) }}</dd>
                            <dt class="text-muted-foreground">Phí vận chuyển</dt><dd class="text-right tabular-nums">{{ formatMoney(order.shipping_fee) }}</dd>
                            <dt class="text-muted-foreground">Giảm giá</dt><dd class="text-right tabular-nums">−{{ formatMoney(order.discount) }}</dd>
                            <dt class="mt-1 border-t pt-2 font-semibold">Khách trả</dt><dd class="mt-1 border-t pt-2 text-right text-lg font-bold tabular-nums text-blue-600">{{ formatMoney(order.total) }}</dd>
                        </dl>
                    </div>
                    <p v-if="!isManual" class="border-t px-5 py-2 text-xs text-muted-foreground">Số tiền khách trả chưa phải tiền thực nhận: phí sàn, phí thanh toán và đối soát sẽ được tính ở module Tài chính.</p>
                </Card>

                <Card caps title="Lịch sử trạng thái">
                    <ol class="relative px-5 py-4 text-sm before:absolute before:bottom-6 before:left-[23px] before:top-6 before:border-l before:border-dashed before:border-blue-200" data-testid="order-history">
                        <li v-for="(h, idx) in order.history" :key="idx" class="relative pb-4 pl-6 last:pb-0">
                            <i class="absolute left-0 top-1.5 size-2 rounded-full bg-blue-400 ring-4 ring-blue-50" />
                            <div>
                                <span v-if="h.from" class="text-muted-foreground">{{ orderStatus[h.from]?.label ?? h.from }} → </span>
                                <span class="font-medium">{{ orderStatus[h.to]?.label ?? h.to }}</span>
                                <span class="ml-2 font-mono text-[11px] text-muted-foreground">{{ h.raw_status }}</span>
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ formatDateTime(h.occurred_at ?? h.created_at) }} · {{ historySource[h.source] ?? h.source }}<span v-if="h.actor"> · {{ h.actor }}</span>
                                <span v-if="h.note"> · {{ h.note }}</span>
                            </div>
                        </li>
                    </ol>
                </Card>

                <Card caps title="Ghi chú nội bộ">
                    <ul v-if="order.notes.length" class="divide-y text-sm">
                        <li v-for="n in order.notes" :key="n.id" class="flex gap-3 px-5 py-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600">{{ initials(n.user ?? '?') }}</span>
                            <div>
                                <div class="whitespace-pre-line">{{ n.body }}</div>
                                <div class="mt-0.5 text-xs text-muted-foreground">{{ n.user }} · {{ formatDateTime(n.created_at) }}</div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có ghi chú.</p>
                    <form v-if="tenant.can('orders.update')" class="flex gap-2 border-t p-4" @submit.prevent="addNote">
                        <Input v-model="note" placeholder="Thêm ghi chú cho đồng nghiệp…" data-testid="note-input" />
                        <Button type="submit" variant="outline" :loading="busy === 'note'">Thêm</Button>
                    </form>
                </Card>
            </div>

            <div class="space-y-5">
                <Card caps title="Xử lý đơn">
                    <div class="space-y-3 px-5 py-4">
                        <p v-if="!actionList.length" class="text-sm text-muted-foreground">Không có thao tác nào ở trạng thái hiện tại.</p>
                        <div v-for="([action, a], idx) in actionList" :key="action" class="flex items-center justify-between gap-2">
                            <Button
                                v-if="tenant.can('orders.update')"
                                :variant="action === 'cancel' || action === 'fail_delivery' ? 'outline' : idx === 0 ? 'default' : 'outline'"
                                :size="idx === 0 && action !== 'cancel' ? 'lg' : 'default'"
                                :class="cn('flex-1', (action === 'cancel' || action === 'fail_delivery') && 'text-rose-600 hover:text-rose-700')"
                                :disabled="a.capability !== 'supported'"
                                :loading="busy === action"
                                :data-testid="`action-${action}`"
                                @click="startAction(action)"
                            >
                                {{ actionLabels[action] }}
                            </Button>
                            <Badge v-if="a.capability !== 'supported'" :tone="capabilityLabels[a.capability]?.tone" :title="'Sàn chưa hỗ trợ hoặc chưa xác minh thao tác này qua API'">{{ capabilityLabels[a.capability]?.label }}</Badge>
                        </div>
                        <p v-if="actionList.some(([, a]) => a.capability !== 'supported')" class="text-xs text-muted-foreground">Thao tác chưa được xác minh với API chính thức của sàn sẽ không được giả lập. Hãy xử lý trên trang của sàn; đơn sẽ tự cập nhật qua webhook/đối soát.</p>
                        <div class="border-t pt-3">
                            <label class="mb-1 block text-xs font-medium text-muted-foreground">Người xử lý</label>
                            <Select
                                :model-value="order.assignee?.id ?? ''"
                                :disabled="!tenant.can('orders.assign')"
                                :options="[{ value: '', label: '— Chưa phân công —' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]"
                                aria-label="Người xử lý"
                                @update:model-value="assign"
                            />
                        </div>
                        <div v-if="order.ship_by_at" :class="cn('flex items-center gap-2 rounded-md px-3 py-2 text-sm', order.flags.overdue ? 'bg-rose-50 text-rose-700' : order.flags.due_soon ? 'bg-amber-50 text-amber-800' : 'bg-slate-50')">
                            <Clock class="size-4 shrink-0" />
                            <span>Hạn giao vận chuyển: <b class="tabular-nums">{{ formatDateTime(order.ship_by_at) }}</b> ({{ deadlineLabel(order.ship_by_at) }})</span>
                        </div>
                    </div>
                </Card>

                <Card v-if="isManual" caps title="Thanh toán" data-testid="payments-card">
                    <template v-if="tenant.can('orders.payment')" #actions>
                        <div class="flex gap-1">
                            <Button v-if="Number(order.balance_due ?? 0) > 0" size="sm" data-testid="add-payment" @click="openPay('payment')"><Banknote /> Thu tiền</Button>
                            <Button v-if="Number(order.paid_amount ?? 0) > Number(order.refunded_amount ?? 0)" size="sm" variant="outline" data-testid="add-refund" @click="openPay('refund')">Hoàn tiền</Button>
                        </div>
                    </template>
                    <div class="grid grid-cols-2 gap-3 p-4">
                        <div class="rounded-lg bg-emerald-50/70 px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Đã thu</p>
                            <p class="font-semibold tabular-nums text-emerald-600">{{ formatMoney(order.paid_amount) }}</p>
                            <p v-if="Number(order.refunded_amount) > 0" class="text-[11px] text-muted-foreground">Đã hoàn {{ formatMoney(order.refunded_amount) }}</p>
                        </div>
                        <div :class="cn('rounded-lg px-3 py-2.5', Number(order.balance_due ?? 0) > 0 ? 'bg-rose-50/70' : 'bg-slate-50')">
                            <p class="text-xs text-muted-foreground">Còn phải thu</p>
                            <p :class="cn('font-semibold tabular-nums', Number(order.balance_due ?? 0) > 0 && 'text-rose-600')" data-testid="balance-due">{{ order.balance_due === null ? '—' : formatMoney(order.balance_due) }}</p>
                        </div>
                    </div>
                    <ul v-if="order.payments.length" class="divide-y border-t text-sm">
                        <li v-for="p in order.payments" :key="p.id" class="flex items-center justify-between gap-2 px-5 py-2.5">
                            <div :class="p.voided_at && 'opacity-50 line-through'">
                                <div>{{ p.kind === 'refund' ? 'Hoàn' : 'Thu' }} · {{ paymentMethodNames[p.method] ?? p.method }}<span v-if="p.reference" class="ml-1 font-mono text-xs">{{ p.reference }}</span></div>
                                <div class="text-xs text-muted-foreground">{{ formatDateTime(p.paid_at) }}<span v-if="p.created_by"> · {{ p.created_by }}</span><span v-if="p.void_reason"> · hủy: {{ p.void_reason }}</span></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="whitespace-nowrap tabular-nums" :class="p.kind === 'refund' ? 'text-rose-600' : ''">{{ p.kind === 'refund' ? '−' : '' }}{{ formatMoney(p.amount) }}</span>
                                <Button v-if="!p.voided_at && p.method !== 'exchange' && tenant.can('orders.payment')" variant="ghost" size="icon" class="size-7" title="Hủy phiếu (nhập nhầm)" @click="voidPayment(p.id)"><Ban class="size-3.5" /></Button>
                            </div>
                        </li>
                    </ul>
                </Card>

                <Card caps title="Khách hàng & giao hàng">
                    <div v-if="order.customer" class="flex items-center gap-3 border-b px-5 py-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">{{ initials(order.customer.name) }}</span>
                        <div class="min-w-0">
                            <RouterLink :to="`/customers/${order.customer.id}`" class="font-medium text-primary hover:underline">{{ order.customer.name }}</RouterLink>
                            <p class="font-mono text-xs text-muted-foreground">{{ order.customer.code }}</p>
                        </div>
                    </div>
                    <dl v-if="order.customer_stats" class="grid grid-cols-2 gap-2 border-b p-4 text-sm" data-testid="customer-stats">
                        <div class="rounded-md bg-slate-50 px-3 py-2"><dt class="text-[11px] text-muted-foreground">Công nợ</dt><dd :class="cn('font-semibold tabular-nums', Number(order.customer_stats.debt) > 0 && 'text-rose-600')">{{ formatMoney(order.customer_stats.debt) }}</dd></div>
                        <div class="rounded-md bg-slate-50 px-3 py-2"><dt class="text-[11px] text-muted-foreground">Đã mua ({{ order.customer_stats.orders_count }} đơn)</dt><dd class="font-semibold tabular-nums">{{ formatMoney(order.customer_stats.total_spent) }}</dd></div>
                        <div class="rounded-md bg-slate-50 px-3 py-2"><dt class="text-[11px] text-muted-foreground">Phiếu trả hàng</dt><dd class="font-semibold tabular-nums">{{ order.customer_stats.returns_count }}</dd></div>
                        <div class="rounded-md bg-slate-50 px-3 py-2"><dt class="text-[11px] text-muted-foreground">Giao thất bại</dt><dd :class="cn('font-semibold tabular-nums', order.customer_stats.failed_deliveries > 0 && 'text-rose-600')">{{ order.customer_stats.failed_deliveries }}</dd></div>
                    </dl>
                    <dl class="space-y-2.5 px-5 py-4 text-sm">
                        <div class="flex gap-3"><UserRound class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Người nhận</dt><dd>{{ order.buyer_name ?? '—' }} <span class="text-muted-foreground">{{ order.buyer_phone }}</span></dd></div></div>
                        <div class="flex gap-3"><MapPin class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Địa chỉ</dt><dd>{{ order.shipping_address ?? '—' }}</dd></div></div>
                        <div class="flex gap-3"><Truck class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Đơn vị vận chuyển · Mã vận đơn</dt><dd>{{ order.carrier ?? '—' }} · <span class="font-mono text-xs">{{ order.tracking_number ?? '—' }}</span></dd></div></div>
                        <div v-if="!isManual || order.is_cod" class="flex gap-3"><Wallet class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Thanh toán</dt><dd>{{ isManual ? 'Thu hộ (COD) khi giao' : order.is_cod ? 'Thu hộ (COD)' : (order.payment_method ?? 'Đã thanh toán') }}</dd></div></div>
                        <div class="flex gap-3"><Warehouse class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Kho xuất</dt><dd>{{ order.warehouse ? `${order.warehouse.code} · ${order.warehouse.name}` : '—' }}</dd></div></div>
                        <div v-if="order.buyer_note" class="flex gap-3"><MessageSquare class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><div><dt class="text-xs text-muted-foreground">Ghi chú của khách</dt><dd class="italic">{{ order.buyer_note }}</dd></div></div>
                        <div v-if="order.cancel_reason" class="flex gap-3"><Ban class="mt-0.5 size-4 shrink-0 text-rose-500" /><div><dt class="text-xs text-muted-foreground">Lý do hủy</dt><dd>{{ order.cancel_reason }}</dd></div></div>
                    </dl>
                    <p class="border-t px-5 py-2 text-xs text-muted-foreground">{{ isManual ? 'Tên, số điện thoại và địa chỉ được mã hóa khi lưu.' : 'Số điện thoại chỉ lưu dạng che; tên và địa chỉ được mã hóa khi lưu.' }}</p>
                </Card>

                <Card caps title="Tags · hẹn giao" data-testid="order-meta">
                    <div class="space-y-3 px-5 py-4">
                        <TagInput v-model="meta.tags" :suggestions="tagSuggestions" :disabled="!tenant.can('orders.update')" @update:model-value="saveMeta" />
                        <FormField label="Ngày hẹn giao cho khách" for="deliver-on">
                            <Input id="deliver-on" v-model="meta.deliver_on" type="date" :disabled="!tenant.can('orders.update')" data-testid="deliver-on" @change="saveMeta" />
                        </FormField>
                    </div>
                </Card>

                <Card caps title="Trả hàng / hoàn tiền">
                    <template v-if="tenant.can('returns.inspect')" #actions>
                        <Button size="sm" variant="outline" data-testid="create-return" @click="openReturn"><Undo2 /> Tạo</Button>
                    </template>
                    <ul v-if="order.returns.length" class="divide-y text-sm">
                        <li v-for="r in order.returns" :key="r.id" class="px-5 py-3">
                            <div class="flex items-center justify-between">
                                <RouterLink :to="`/returns/${r.id}`" class="hover:underline">
                                    <div class="font-mono text-xs font-semibold text-primary">{{ r.number }}</div>
                                    <div class="text-xs text-muted-foreground">{{ returnTypes[r.type] }}</div>
                                </RouterLink>
                                <Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label }}</Badge>
                            </div>
                            <div v-if="r.exchange_order" class="mt-1 text-xs">
                                Đổi sang <RouterLink :to="`/orders/${r.exchange_order.id}`" class="font-mono text-primary hover:underline">{{ r.exchange_order.external_order_id }}</RouterLink>
                                <span v-if="Number(r.exchange_credit) > 0" class="text-muted-foreground"> · bù trừ {{ formatMoney(r.exchange_credit) }}</span>
                            </div>
                            <RouterLink
                                v-else-if="r.type !== 'refund_only' && r.status !== 'rejected' && tenant.can('orders.create')"
                                :to="{ path: '/orders/new', query: { exchange: r.id } }"
                                class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                data-testid="create-exchange"
                            >
                                <Repeat class="size-3" /> Tạo đơn đổi hàng
                            </RouterLink>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
                </Card>
            </div>
        </div>


        <PrintDialog v-model:open="printOpen" :orders="[order]" />

        <Dialog v-model:open="cancelOpen" title="Hủy đơn" :description="isManual ? 'Hàng đang giữ được nhả lại kho ngay. Nếu khách đã trả tiền, hãy ghi nhận hoàn tiền sau khi hủy.' : 'Yêu cầu hủy được gửi lên sàn. Hàng đang giữ sẽ được nhả khi sàn xác nhận hủy.'">
            <FormField label="Lý do"><Input v-model="cancelReason" placeholder="Chọn bên dưới hoặc gõ lý do khác" data-testid="cancel-reason" /></FormField>
            <div v-if="cancelReasons.length" class="mt-2 flex flex-wrap gap-1.5" data-testid="cancel-reason-options">
                <button v-for="r in cancelReasons" :key="r" type="button" :class="['rounded-full border px-2.5 py-1 text-xs', cancelReason === r ? 'border-blue-300 bg-blue-50 text-blue-700' : 'hover:bg-muted']" @click="cancelReason = r">{{ r }}</button>
            </div>
            <template #footer>
                <Button variant="outline" @click="cancelOpen = false">Đóng</Button>
                <Button variant="destructive" :loading="busy === 'cancel'" @click="act('cancel', { reason: cancelReason || null })">Hủy đơn</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="handoverOpen" title="Bàn giao vận chuyển" description="Hàng được trừ khỏi kho khi bàn giao. Kho phải đủ hàng.">
            <div class="space-y-3">
                <FormField label="Đơn vị vận chuyển"><Input v-model="handover.carrier" placeholder="GHN, GHTK, tự giao…" /></FormField>
                <FormField label="Mã vận đơn"><Input v-model="handover.tracking_number" data-testid="tracking-input" /></FormField>
            </div>
            <template #footer>
                <Button variant="outline" @click="handoverOpen = false">Đóng</Button>
                <Button :loading="busy === 'handover'" data-testid="confirm-handover" @click="act('handover', { carrier: handover.carrier || null, tracking_number: handover.tracking_number || null })">Bàn giao</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="payOpen" :title="payForm.kind === 'refund' ? 'Hoàn tiền cho khách' : 'Thu tiền'">
            <div class="space-y-3">
                <FormField label="Hình thức"><Select v-model="payForm.method" :options="Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))" /></FormField>
                <FormField label="Số tiền" :error="payError?.field('amount')"><Input v-model="payForm.amount" type="number" min="1" data-testid="pay-amount-input" /></FormField>
                <FormField label="Mã giao dịch (nếu có)"><Input v-model="payForm.reference" /></FormField>
                <FormField label="Ghi chú"><Input v-model="payForm.note" /></FormField>
                <p v-if="payError && !payError.field('amount')" class="text-sm text-destructive">{{ payError.message }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="payOpen = false">Đóng</Button>
                <Button :loading="busy === 'pay'" data-testid="confirm-pay" @click="pay">Ghi nhận</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="returnOpen" title="Tạo phiếu trả hàng" size="lg" description="Tồn kho chỉ được cộng lại khi kiểm hàng hoàn về, theo số lượng còn bán được. Hoàn tiền không kèm hàng không cộng kho.">
            <div class="space-y-4">
                <FormField label="Loại">
                    <Select v-model="returnForm.type" :options="[{ value: 'return', label: returnTypes.return! }, { value: 'refund_only', label: returnTypes.refund_only! }]" />
                </FormField>
                <table v-if="returnForm.type === 'return'" class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1">Sản phẩm</th><th class="py-1 text-right">Đã mua</th><th class="w-28 py-1 text-right">Trả lại</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="i in order.items" :key="i.id">
                            <td class="py-2">{{ i.name }} <span class="text-xs text-muted-foreground">{{ i.variant_name }}</span></td>
                            <td class="py-2 text-right tabular-nums">{{ i.quantity }}</td>
                            <td class="py-2"><Input v-model.number="returnForm.lines[i.id]" type="number" min="0" :max="i.quantity" class="text-right" /></td>
                        </tr>
                    </tbody>
                </table>
                <FormField label="Lý do"><Input v-model="returnForm.reason" placeholder="Chọn bên dưới hoặc gõ lý do khác" /></FormField>
                <div v-if="returnReasons.length" class="-mt-1 flex flex-wrap gap-1.5" data-testid="return-reason-options">
                    <button v-for="r in returnReasons" :key="r" type="button" :class="['rounded-full border px-2.5 py-1 text-xs', returnForm.reason === r ? 'border-blue-300 bg-blue-50 text-blue-700' : 'hover:bg-muted']" @click="returnForm.reason = r">{{ r }}</button>
                </div>
                <FormField label="Số tiền hoàn (nếu có)" :error="returnErrors?.field('refund_amount')"><Input v-model="returnForm.refund_amount" type="number" min="0" /></FormField>
                <p v-if="returnErrors" class="text-sm text-destructive">{{ returnErrors.field('lines') ?? returnErrors.message }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="returnOpen = false">Hủy</Button>
                <Button :loading="busy === 'return'" @click="createReturn">Tạo phiếu</Button>
            </template>
        </Dialog>
    </template>
</template>
