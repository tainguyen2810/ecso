<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Pencil, Undo2, X } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { marketplaceNames, returnStatus } from '../orders/labels';
import type { OrderReturnData } from '@/shared/types';
import ShipmentActionButtons from './ShipmentActionButtons.vue';
import ShipmentEditDialog from './ShipmentEditDialog.vue';
import { carrierText, shipmentTone, statusText, type ShipmentData } from './shipments';

/** One shipment opened from the list (ngăn kéo bên phải): details, history, related documents, manual steps. */
const props = defineProps<{ id: string }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const tenant = useTenantStore();

const shipment = ref<(ShipmentData & { returns?: OrderReturnData[] }) | null>(null);
const error = ref<string | null>(null);
const editOpen = ref(false);

async function load() {
    try {
        const { data } = await api.get(`/shipments/${props.id}`);
        shipment.value = data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
function changed() {
    emit('changed');
    void load();
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && !editOpen.value) emit('close');
}
onMounted(() => {
    void load();
    window.addEventListener('keydown', onKey);
});
onUnmounted(() => window.removeEventListener('keydown', onKey));

const sourceText: Record<string, string> = { manual: 'Nhập mã', self: 'Tự giao', api: 'API hãng', marketplace: 'Sàn tạo', import: 'Nhập file' };
const codText: Record<string, string> = { none: 'Không thu hộ', pending: 'chưa thu', collected: 'hãng đã thu, chưa đối soát', remitted: 'đã đối soát', returned: 'hàng hoàn, không thu' };
const feeText: Record<string, string> = { estimated: 'ước tính', confirmed: 'hãng đã tính' };
const dot = (status: string) => (['failed'].includes(status) ? 'bg-red-400 ring-red-50' : ['returning', 'returned', 'cancelled'].includes(status) ? 'bg-amber-400 ring-amber-50' : status === 'delivered' ? 'bg-emerald-400 ring-emerald-50' : 'bg-blue-400 ring-blue-50');
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-40 flex justify-end bg-black/30" data-testid="shipment-drawer" @mousedown.self="emit('close')">
            <aside class="flex h-full w-full max-w-[540px] flex-col overflow-y-auto bg-card shadow-xl">
                <div v-if="error" class="p-5 text-sm text-destructive">{{ error }}</div>
                <div v-else-if="!shipment" class="space-y-3 p-5"><Skeleton class="h-16" /><Skeleton class="h-40" /><Skeleton class="h-40" /></div>
                <div v-else class="space-y-4 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-muted-foreground">Vận đơn · {{ carrierText(shipment) }} · {{ sourceText[shipment.source] }}</p>
                            <p class="flex flex-wrap items-center gap-2 text-lg font-semibold">
                                <span class="font-mono" data-testid="drawer-tracking">{{ shipment.tracking_number ?? 'Không có mã' }}</span>
                                <Badge :tone="shipmentTone[shipment.status]" data-testid="drawer-status">{{ statusText(shipment) }}</Badge>
                            </p>
                            <p v-if="shipment.order" class="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                                Đơn
                                <RouterLink :to="`/orders/${shipment.order.id}`" class="inline-flex items-center gap-1 text-primary hover:underline">
                                    <MarketplaceLogo :marketplace="shipment.order.marketplace" :name="marketplaceNames[shipment.order.marketplace]" size="sm" />{{ shipment.order.external_order_id }}
                                </RouterLink>
                                <span>· {{ shipment.order.channel ?? marketplaceNames[shipment.order.marketplace] }}</span>
                                <span v-if="shipment.created_at">· tạo {{ formatDateTime(shipment.created_at) }}</span>
                            </p>
                        </div>
                        <button class="rounded p-1 text-muted-foreground hover:bg-muted" aria-label="Đóng" @click="emit('close')"><X class="size-5" /></button>
                    </div>

                    <Alert v-if="shipment.status === 'failed'" tone="amber">
                        Giao thất bại<template v-if="shipment.failure_reason">: <b>{{ shipment.failure_reason }}</b></template>. Chọn <b>Giao lại</b> nếu hãng đi giao tiếp, hoặc <b>Chuyển hoàn</b> nếu hàng quay về kho.
                    </Alert>

                    <div class="flex flex-wrap gap-2">
                        <ShipmentActionButtons :shipment="shipment" @done="changed" />
                        <Button v-if="tenant.can('orders.update') && !['cancelled'].includes(shipment.status)" size="sm" variant="outline" class="ml-auto" data-testid="shipment-edit" @click="editOpen = true"><Pencil /> Sửa vận đơn</Button>
                    </div>
                    <p v-if="shipment.source === 'marketplace'" class="text-xs text-muted-foreground">Đơn sàn: trạng thái giao do sàn cập nhật khi đồng bộ.</p>

                    <dl class="grid grid-cols-[130px_1fr] gap-x-4 gap-y-2 rounded-lg border p-3 text-sm">
                        <dt class="text-muted-foreground">Người nhận</dt>
                        <dd>{{ shipment.order?.recipient ?? '—' }} <span v-if="shipment.order?.phone" class="text-muted-foreground">· {{ shipment.order.phone }}</span></dd>
                        <dt class="text-muted-foreground">Địa chỉ</dt>
                        <dd>{{ shipment.order?.address ?? shipment.order?.area ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Kho xuất</dt>
                        <dd>{{ shipment.warehouse?.name ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Kiện · khối lượng</dt>
                        <dd>{{ shipment.packages }} kiện<template v-if="shipment.weight_g"> · {{ shipment.weight_g }} g</template></dd>
                        <dt class="text-muted-foreground">COD phải thu</dt>
                        <dd class="tabular-nums">
                            <template v-if="Number(shipment.cod_amount) > 0"><b>{{ formatMoney(shipment.cod_amount) }}</b> <span class="text-muted-foreground">· {{ codText[shipment.cod_status] }}</span></template>
                            <span v-else class="text-muted-foreground">Không thu hộ</span>
                        </dd>
                        <dt class="text-muted-foreground">Phí hãng</dt>
                        <dd class="tabular-nums">
                            <template v-if="shipment.fee_actual">{{ formatMoney(shipment.fee_actual) }} <span class="text-muted-foreground">· {{ feeText.confirmed }}</span></template>
                            <template v-else-if="shipment.fee_estimated">{{ formatMoney(shipment.fee_estimated) }} <span class="text-muted-foreground">· {{ feeText.estimated }}</span></template>
                            <span v-else class="text-muted-foreground">Chưa có (ghi khi đối soát)</span>
                        </dd>
                    </dl>

                    <section>
                        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Lịch sử vận đơn</h3>
                        <ol class="relative ml-2 space-y-3 border-l border-dashed border-blue-200" data-testid="shipment-timeline">
                            <li v-for="(e, i) in shipment.events" :key="i" class="relative ml-4 text-sm">
                                <span :class="cn('absolute -left-[21px] top-1 size-2.5 rounded-full ring-4', dot(e.status))" />
                                <b>{{ e.status_label }}</b><template v-if="e.description"> · {{ e.description }}</template>
                                <div class="text-xs tabular-nums text-muted-foreground">{{ formatDateTime(e.occurred_at) }}<template v-if="e.actor"> · {{ e.actor }}</template></div>
                            </li>
                        </ol>
                    </section>

                    <section>
                        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Chứng từ liên quan</h3>
                        <div v-for="r in shipment.returns ?? []" :key="r.id" class="flex items-center gap-1.5 text-sm">
                            <Undo2 class="size-4 text-muted-foreground" /> Phiếu trả
                            <RouterLink :to="`/returns/${r.id}`" class="text-primary hover:underline">{{ r.number }}</RouterLink>
                            <Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label }}</Badge>
                        </div>
                        <p v-if="!(shipment.returns ?? []).length" class="text-sm text-muted-foreground">Chưa có phiếu trả hàng.</p>
                    </section>
                </div>
            </aside>
        </div>
        <ShipmentEditDialog v-if="shipment" v-model:open="editOpen" :shipment="shipment" @saved="changed" />
    </Teleport>
</template>
