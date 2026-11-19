<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { AlarmClock, Check, ChevronDown, ChevronUp, CircleCheck, ExternalLink, MapPin, Package, Pencil, ReceiptText, StickyNote, Truck, Warehouse, X } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber, initials } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import PrintDialog from './PrintDialog.vue';
import { actionLabels, marketplaceNames, orderStatus, paymentStatus } from './labels';
import type { OrderDetail } from '@/shared/types';

/**
 * Order opened in a side panel over the list: steps, lines with pictures, money, buyer and delivery, notes,
 * and the next step as the main button. Up/down move to the previous/next order of the list.
 */
const props = defineProps<{ id: string; position?: number; total?: number }>();
const emit = defineEmits<{ close: []; prev: []; next: []; changed: [] }>();
const tenant = useTenantStore();
const toast = useToast();

const order = ref<OrderDetail | null>(null);
const printOpen = ref(false);
const error = ref<string | null>(null);
const busy = ref(false);

async function load() {
    error.value = null;
    try {
        const { data } = await api.get(`/orders/${props.id}`);
        order.value = data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch(() => props.id, () => ((order.value = null), load()));

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && !printOpen.value) emit('close');
    if ((e.target as HTMLElement)?.closest('input, textarea, select')) return;
    if (e.key === 'ArrowUp' || e.key === 'k') emit('prev');
    if (e.key === 'ArrowDown' || e.key === 'j') emit('next');
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));

const steps = [
    { key: 'placed', label: 'Đặt hàng' },
    { key: 'confirmed', label: 'Xác nhận' },
    { key: 'packed', label: 'Đóng gói' },
    { key: 'handed', label: 'Bàn giao' },
    { key: 'done', label: 'Hoàn tất' },
];
const reached: Record<string, number> = { UNPAID: 0, PENDING_CONFIRMATION: 0, READY_TO_PACK: 1, PACKED: 2, HANDED_OVER: 3, IN_TRANSIT: 3, DELIVERED: 3, COMPLETED: 4 };
const stepIndex = computed(() => (order.value ? (reached[order.value.status] ?? -1) : -1));
const stepTime = (i: number) => {
    const o = order.value;
    if (!o) return null;
    if (i === 0) return o.placed_at;
    if (i === 3) return o.handed_over_at;
    const target = ['', 'READY_TO_PACK', 'PACKED', '', 'COMPLETED'][i];
    const h = o.history.find((x) => x.to === target);
    return h?.occurred_at ?? h?.created_at ?? null;
};

const OPEN = ['UNPAID', 'PENDING_CONFIRMATION', 'READY_TO_PACK', 'PACKED'];
const due = computed(() => {
    const o = order.value;
    if (!o?.ship_by_at || !OPEN.includes(o.status)) return null;
    const hours = (new Date(o.ship_by_at).getTime() - Date.now()) / 3600000;
    const at = formatDateTime(o.ship_by_at);
    if (hours < 0) return { late: true, text: `Quá hạn giao (${at})` };
    return { late: hours < 6, text: `Giao trước ${at} (còn ${hours < 1 ? `${Math.max(1, Math.round(hours * 60))} phút` : `${Math.round(hours)} giờ`})` };
});

const units = computed(() => (order.value?.items ?? []).reduce((s, i) => s + i.quantity, 0));
const shortage = computed(() => (order.value?.items ?? []).some((i) => (i.reservation?.shortage ?? 0) > 0));

const nextAction: Record<string, string> = { PENDING_CONFIRMATION: 'confirm', READY_TO_PACK: 'pack', PACKED: 'handover' };
const action = computed(() => {
    const o = order.value;
    if (!o || !tenant.can('orders.update')) return null;
    const a = nextAction[o.status];
    if (!a) return null;
    return o.actions?.[a] && !o.actions[a].allowed ? null : a;
});
async function run() {
    if (!order.value || !action.value) return;
    busy.value = true;
    try {
        const { data } = await api.post('/orders/bulk', { ids: [order.value.id], action: action.value });
        const r = (data.data as { ok: boolean; message: string | null }[])[0];
        if (r?.ok) toast.success(`${actionLabels[action.value]}: ${order.value.external_order_id}`);
        else toast.error(r?.message ?? 'Không xử lý được đơn này.');
        await load();
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}
const source = computed(() => (order.value ? (order.value.channel?.name ?? order.value.shop?.name ?? null) : null));
</script>

<template>
    <PrintDialog v-if="order" v-model:open="printOpen" :orders="[order]" />
    <div class="fixed inset-0 z-40 bg-slate-900/30" aria-hidden="true" @click="emit('close')" />
    <aside class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[640px] flex-col bg-card shadow-2xl" role="dialog" aria-label="Chi tiết đơn" data-testid="order-quick-view">
        <div class="flex items-center gap-1 border-b px-5 py-2.5 text-sm text-muted-foreground">
            <template v-if="total">
                <button type="button" class="rounded p-1 hover:bg-muted disabled:opacity-40" aria-label="Đơn trước" :disabled="!position || position <= 1" @click="emit('prev')"><ChevronUp class="size-4" /></button>
                <button type="button" class="rounded p-1 hover:bg-muted disabled:opacity-40" aria-label="Đơn sau" :disabled="!position || position >= total" @click="emit('next')"><ChevronDown class="size-4" /></button>
                <span class="ml-1">Đơn {{ position }} / {{ total }}</span>
            </template>
            <RouterLink :to="`/orders/${id}`" class="ml-auto inline-flex items-center gap-1.5 hover:text-foreground"><ExternalLink class="size-4" /> Mở trang đầy đủ</RouterLink>
            <button type="button" class="ml-2 rounded p-1 hover:bg-muted" aria-label="Đóng" @click="emit('close')"><X class="size-5" /></button>
        </div>

        <p v-if="error" class="p-5 text-sm text-destructive">{{ error }}</p>
        <div v-else-if="!order" class="space-y-3 p-5"><Skeleton class="h-16" /><Skeleton class="h-32" /><Skeleton class="h-40" /></div>
        <template v-else>
            <div class="flex-1 overflow-y-auto">
                <div class="border-b px-5 pb-4 pt-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <MarketplaceLogo :marketplace="order.marketplace" :name="marketplaceNames[order.marketplace]" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-lg font-semibold">{{ order.external_order_id }}</span>
                                <Badge :tone="orderStatus[order.status]?.tone">{{ orderStatus[order.status]?.label ?? order.status }}</Badge>
                            </p>
                            <p class="text-sm text-muted-foreground">{{ marketplaceNames[order.marketplace] ?? order.marketplace }}<template v-if="source"> · {{ source }}</template> · đặt {{ formatDateTime(order.placed_at) }}</p>
                            <p v-if="due" :class="['mt-1 inline-flex items-center gap-1 text-sm font-medium', due.late ? 'text-rose-600' : 'text-amber-600']"><AlarmClock class="size-4" /> {{ due.text }}</p>
                        </div>
                    </div>
                    <ol v-if="stepIndex >= 0" class="mt-4 flex items-center text-xs">
                        <li v-for="(s, i) in steps" :key="s.key" class="flex flex-1 items-center last:flex-none" :title="i <= stepIndex && stepTime(i) ? formatDateTime(stepTime(i)) : undefined">
                            <span :class="['grid size-5 shrink-0 place-items-center rounded-full border-2', i <= stepIndex ? 'border-primary bg-primary text-white' : i === stepIndex + 1 ? 'border-primary bg-card' : 'border-slate-200 bg-card']">
                                <Check v-if="i <= stepIndex" class="size-3" :stroke-width="3" />
                            </span>
                            <span :class="['ml-1.5 whitespace-nowrap', i <= stepIndex + 1 ? 'font-medium text-foreground' : 'text-muted-foreground']">{{ s.label }}</span>
                            <span v-if="i < steps.length - 1" :class="['mx-2 h-0.5 flex-1 rounded', i < stepIndex ? 'bg-primary' : 'bg-slate-200']" />
                        </li>
                    </ol>
                </div>

                <section class="px-5 py-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold">Sản phẩm <span class="font-normal text-muted-foreground">· {{ formatNumber(units) }}</span></h3>
                        <span v-if="order.warehouse && !shortage && OPEN.includes(order.status)" class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600"><CircleCheck class="size-3.5" /> Đủ hàng trong {{ order.warehouse.name }}</span>
                        <span v-else-if="shortage" class="text-xs font-medium text-rose-600">Thiếu hàng trong kho</span>
                    </div>
                    <ul class="divide-y rounded-xl border">
                        <li v-for="i in order.items" :key="i.id" class="flex items-center gap-3 px-3 py-2.5">
                            <img v-if="i.image" :src="i.image" alt="" class="size-12 shrink-0 rounded-lg border object-cover" />
                            <span v-else class="grid size-12 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400"><Package class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ i.name }}<span v-if="i.variant_name" class="font-normal text-muted-foreground"> · {{ i.variant_name }}</span></p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-mono">{{ i.variant?.sku ?? i.seller_sku ?? '—' }}</span>
                                    <template v-if="i.reservation"> · <span :class="i.reservation.shortage ? 'text-rose-600' : 'text-emerald-600'">{{ i.reservation.shortage ? `Thiếu ${i.reservation.shortage}` : `Giữ ${i.reservation.quantity}` }}</span></template>
                                    <template v-else-if="!i.variant"> · <span class="text-amber-600">Chưa liên kết SKU</span></template>
                                </p>
                            </div>
                            <p class="whitespace-nowrap text-sm tabular-nums">{{ formatMoney(i.unit_price) }} <span class="text-muted-foreground">× {{ i.quantity }}</span></p>
                        </li>
                    </ul>
                </section>

                <div class="grid gap-3 px-5 pb-4 sm:grid-cols-2">
                    <section class="rounded-xl border p-4 text-sm">
                        <h3 class="mb-2 font-semibold">Thanh toán</h3>
                        <dl class="space-y-1.5">
                            <div class="flex justify-between"><dt class="text-muted-foreground">Tiền hàng</dt><dd class="tabular-nums">{{ formatMoney(order.subtotal) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-muted-foreground">Phí giao</dt><dd class="tabular-nums">{{ formatMoney(order.shipping_fee) }}</dd></div>
                            <div v-if="Number(order.discount)" class="flex justify-between"><dt class="text-muted-foreground">Giảm giá</dt><dd class="tabular-nums">−{{ formatMoney(order.discount) }}</dd></div>
                            <div class="flex justify-between border-t pt-2 font-semibold"><dt>Khách phải trả</dt><dd class="tabular-nums">{{ formatMoney(order.total) }}</dd></div>
                        </dl>
                        <Badge v-if="order.source === 'manual' && order.payment_status" :tone="paymentStatus[order.payment_status]?.tone" class="mt-2">{{ paymentStatus[order.payment_status]?.label }}</Badge>
                        <p v-else-if="order.marketplace !== 'manual'" class="mt-2 inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs">Sàn thu tiền</p>
                    </section>
                    <section class="rounded-xl border p-4 text-sm">
                        <h3 class="mb-2 font-semibold">Khách hàng &amp; giao hàng</h3>
                        <div class="flex items-center gap-2.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">{{ initials(order.customer?.name ?? order.buyer_name ?? 'K') }}</span>
                            <div class="min-w-0">
                                <RouterLink v-if="order.customer" :to="`/customers/${order.customer.id}`" class="block truncate font-medium text-primary hover:underline">{{ order.customer.name }}</RouterLink>
                                <p v-else class="truncate font-medium">{{ order.buyer_name ?? 'Khách lẻ' }}</p>
                                <p v-if="order.customer_stats" class="text-xs text-muted-foreground">Mua lần {{ order.customer_stats.orders_count }} · {{ formatMoney(order.customer_stats.total_spent) }}</p>
                            </div>
                        </div>
                        <ul class="mt-3 space-y-1.5 text-muted-foreground">
                            <li v-if="order.buyer_phone || order.shipping_address" class="flex gap-2"><MapPin class="mt-0.5 size-4 shrink-0" /><span><span v-if="order.customer && order.buyer_name" class="text-foreground">{{ order.buyer_name }} · </span><span class="text-foreground">{{ order.buyer_phone }}</span><br v-if="order.buyer_phone" />{{ order.shipping_address }}</span></li>
                            <li v-if="order.carrier || order.tracking_number" class="flex gap-2"><Truck class="mt-0.5 size-4 shrink-0" /><span>{{ order.carrier ?? 'Vận chuyển' }} · <span :class="order.tracking_number ? 'font-mono text-foreground' : ''">{{ order.tracking_number ?? 'chưa có mã vận đơn' }}</span></span></li>
                            <li v-if="order.warehouse" class="flex gap-2"><Warehouse class="mt-0.5 size-4 shrink-0" />{{ order.warehouse.name }}</li>
                        </ul>
                    </section>
                </div>

                <section v-if="order.buyer_note || order.notes.length" class="mx-5 mb-5 rounded-xl border bg-amber-50/50 p-4 text-sm">
                    <h3 class="mb-1.5 flex items-center gap-1.5 font-semibold"><StickyNote class="size-4 text-amber-600" /> Ghi chú</h3>
                    <p v-if="order.buyer_note" class="italic">Khách: {{ order.buyer_note }}</p>
                    <p v-for="n in order.notes.slice(-3)" :key="n.id" class="mt-1">{{ n.body }} <span class="text-xs text-muted-foreground">· {{ n.user }}</span></p>
                </section>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t px-5 py-3">
                <Button v-if="order.source === 'manual'" variant="outline" @click="printOpen = true"><ReceiptText /> In đơn</Button>
                <RouterLink v-if="order.editable && tenant.can('orders.create') && tenant.can('orders.update')" :to="`/orders/${order.id}/edit`"><Button variant="outline"><Pencil /> Sửa đơn</Button></RouterLink>
                <Button v-if="action" class="ml-auto" :loading="busy" data-testid="quick-view-action" @click="run">{{ actionLabels[action] }}</Button>
            </div>
        </template>
    </aside>
</template>
