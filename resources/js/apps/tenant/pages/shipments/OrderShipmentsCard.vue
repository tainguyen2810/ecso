<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { History, Plus } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { CARRIERS } from '@/shared/lib/geo';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import ShipmentActionButtons from './ShipmentActionButtons.vue';
import ShipmentDrawer from './ShipmentDrawer.vue';
import { carrierText, shipmentTone, statusText, type ShipmentData } from './shipments';

/** "Vận đơn" card on the order: its shipments, the next step on the live one, and "Tạo vận đơn" for own parcels. */
const props = defineProps<{ orderId: string; shipments: ShipmentData[]; shipsItself: boolean; orderStatus: string }>();
const emit = defineEmits<{ changed: [] }>();
const tenant = useTenantStore();
const toast = useToast();

const live = computed(() => props.shipments.some((s) => s.status !== 'cancelled'));
const canCreate = computed(() => props.shipsItself && !live.value && tenant.can('shipments.manage') && !['CANCELLED', 'RETURNED', 'UNPAID'].includes(props.orderStatus));
const ordered = computed(() => [...props.shipments].sort((a, b) => Number(a.status === 'cancelled') - Number(b.status === 'cancelled')));
const openId = ref<string | null>(null);

const createOpen = ref(false);
const form = reactive({ mode: 'manual' as 'manual' | 'self', carrier: 'GHN', tracking_number: '', weight_g: '', packages: '1', fee_estimated: '' });
const errors = ref<Record<string, string | undefined>>({});
const busy = ref(false);
let key = newIdempotencyKey();

function openCreate() {
    Object.assign(form, { mode: 'manual', carrier: 'GHN', tracking_number: '', weight_g: '', packages: '1', fee_estimated: '' });
    errors.value = {};
    createOpen.value = true;
}
async function create() {
    busy.value = true;
    errors.value = {};
    try {
        await api.post(`/orders/${props.orderId}/shipments`, {
            mode: form.mode,
            carrier: form.mode === 'self' ? null : form.carrier,
            tracking_number: form.tracking_number || null,
            weight_g: form.weight_g ? Number(form.weight_g) : null,
            packages: Number(form.packages) || 1,
            fee_estimated: form.fee_estimated ? Number(form.fee_estimated) : null,
        }, idempotent(key));
        key = newIdempotencyKey();
        createOpen.value = false;
        toast.success('Đã tạo vận đơn');
        emit('changed');
    } catch (e) {
        const err = e as ApiError;
        errors.value = { tracking_number: err.field('tracking_number'), carrier: err.field('carrier'), order: err.field('order') };
        if (!Object.values(errors.value).some(Boolean)) toast.error(err.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Card caps title="Vận đơn" data-testid="order-shipments">
        <template #actions>
            <Button v-if="canCreate" size="sm" variant="outline" data-testid="new-shipment" @click="openCreate"><Plus /> Tạo vận đơn</Button>
        </template>
        <div class="space-y-3 px-5 py-4">
            <p v-if="!shipments.length" class="text-sm text-muted-foreground">
                {{ shipsItself ? 'Chưa có vận đơn. Tạo vận đơn khi đã có mã của hãng, hoặc khi shop tự giao. Bàn giao đơn kèm mã vận đơn cũng tạo vận đơn.' : 'Sàn sẽ tạo vận đơn khi đơn được giao cho hãng.' }}
            </p>
            <div
                v-for="s in ordered"
                :key="s.id"
                :class="cn('flex flex-wrap items-center gap-3 rounded-lg border p-3', s.status === 'cancelled' && 'bg-slate-50 text-muted-foreground')"
                data-testid="order-shipment"
            >
                <div class="min-w-0 flex-1">
                    <div :class="cn('flex flex-wrap items-center gap-2 font-medium', s.status === 'cancelled' && 'line-through')">
                        {{ carrierText(s) }} · <span class="font-mono text-primary">{{ s.tracking_number ?? 'không có mã' }}</span>
                        <Badge v-if="s.status !== 'cancelled'" :tone="shipmentTone[s.status]">{{ statusText(s) }}</Badge>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        <template v-if="s.status === 'cancelled'">Hủy vận đơn {{ s.cancelled_at ? formatDateTime(s.cancelled_at) : '' }}</template>
                        <template v-else>
                            {{ s.packages }} kiện<template v-if="s.weight_g"> · {{ s.weight_g }} g</template>
                            <template v-if="Number(s.cod_amount) > 0"> · COD {{ formatMoney(s.cod_amount) }}</template>
                            <template v-if="s.picked_up_at"> · lấy hàng {{ formatDateTime(s.picked_up_at) }}</template>
                            <template v-if="s.failure_reason && s.status === 'failed'"> · {{ s.failure_reason }}</template>
                        </template>
                    </div>
                </div>
                <ShipmentActionButtons v-if="s.status !== 'cancelled'" :shipment="s" :limit="1" @done="emit('changed')" />
                <Button size="sm" variant="outline" data-testid="shipment-open" @click="openId = s.id"><History /> Xem lịch sử</Button>
            </div>
        </div>
    </Card>

    <ShipmentDrawer v-if="openId" :id="openId" :key="openId" @close="openId = null" @changed="emit('changed')" />

    <Dialog v-model:open="createOpen" title="Tạo vận đơn" description="Hãng chưa kết nối API: tạo đơn trên app của hãng rồi dán mã vận đơn vào đây." size="lg">
        <div class="mb-3 grid gap-2 sm:grid-cols-3">
            <button :class="cn('rounded-lg border p-3 text-left text-sm', form.mode === 'manual' && 'border-blue-300 bg-blue-50/70')" data-testid="shipment-mode-manual" @click="form.mode = 'manual'">
                <b>Nhập mã có sẵn</b><div class="text-xs text-muted-foreground">Đã tạo đơn trên app hãng, dán mã vận đơn</div>
            </button>
            <button :class="cn('rounded-lg border p-3 text-left text-sm', form.mode === 'self' && 'border-blue-300 bg-blue-50/70')" data-testid="shipment-mode-self" @click="form.mode = 'self'">
                <b>Tự giao</b><div class="text-xs text-muted-foreground">Shipper của shop, không cần mã</div>
            </button>
            <div class="cursor-not-allowed rounded-lg border p-3 text-sm text-muted-foreground/70" title="Cần tài khoản API của hãng">
                <b>Gửi qua API hãng</b><div class="text-xs">Chưa kết nối (cần tài khoản hãng)</div>
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label v-if="form.mode === 'manual'" class="space-y-1 text-sm">
                <span class="text-muted-foreground">Hãng vận chuyển</span>
                <Input v-model="form.carrier" list="new-shipment-carriers" data-testid="new-shipment-carrier" />
                <datalist id="new-shipment-carriers"><option v-for="c in CARRIERS" :key="c" :value="c" /></datalist>
                <span v-if="errors.carrier" class="text-xs text-destructive">{{ errors.carrier }}</span>
            </label>
            <label class="space-y-1 text-sm">
                <span class="text-muted-foreground">Mã vận đơn{{ form.mode === 'self' ? ' (nếu có)' : '' }}</span>
                <Input v-model="form.tracking_number" data-testid="new-shipment-tracking" />
                <span v-if="errors.tracking_number" class="text-xs text-destructive">{{ errors.tracking_number }}</span>
            </label>
            <label class="space-y-1 text-sm"><span class="text-muted-foreground">Khối lượng (g)</span><Input v-model="form.weight_g" type="number" min="1" /></label>
            <label class="space-y-1 text-sm"><span class="text-muted-foreground">Số kiện</span><Input v-model="form.packages" type="number" min="1" /></label>
            <label class="space-y-1 text-sm sm:col-span-2">
                <span class="text-muted-foreground">Phí ship ước tính (₫, không bắt buộc)</span><Input v-model="form.fee_estimated" type="number" min="0" />
                <span class="text-[11px] text-muted-foreground">Chỉ để xem. Phí thật vào sổ khi đối soát COD hoặc lập phiếu chi.</span>
            </label>
        </div>
        <p v-if="errors.order" class="mt-3 text-sm text-destructive">{{ errors.order }}</p>
        <div class="mt-4 flex justify-end gap-2">
            <Button variant="outline" @click="createOpen = false">Hủy</Button>
            <Button :disabled="busy" data-testid="new-shipment-save" @click="create">Tạo vận đơn</Button>
        </div>
    </Dialog>
</template>
