<script setup lang="ts">
import { computed, ref } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { actionNeedsReason, actionOrder, type ShipmentData } from './shipments';

/**
 * What the shop records by hand on a shipment (lấy hàng, giao, thất bại, giao lại, chuyển hoàn, hủy). The server says
 * which apply now and why not; steps that are also order steps run through the order's own action.
 */
const props = withDefaults(defineProps<{ shipment: ShipmentData; limit?: number; size?: 'sm' | 'default' }>(), { limit: 99, size: 'sm' });
const emit = defineEmits<{ done: [shipment: ShipmentData] }>();
const tenant = useTenantStore();
const toast = useToast();

const list = computed(() => {
    const a = props.shipment.actions ?? {};
    // A parcel made at the carrier is cancelled there first ("Hủy trên hãng" in the drawer).
    const atCarrier = props.shipment.source === 'api' && !!props.shipment.tracking_number;
    return actionOrder.filter((k) => a[k] && !(atCarrier && k === 'cancel')).map((k) => ({ key: k, ...a[k]! })).slice(0, props.limit);
});
const asking = ref<string | null>(null);
const reason = ref('');
const busy = ref(false);

function start(key: string) {
    if (actionNeedsReason[key]) {
        reason.value = '';
        asking.value = key;
        return;
    }
    void run(key);
}

async function run(key: string) {
    busy.value = true;
    try {
        const { data } = await api.post(`/shipments/${props.shipment.id}/actions/${key}`, { reason: reason.value || undefined });
        asking.value = null;
        toast.success(`${data.data.tracking_number ?? 'Vận đơn'}: ${data.data.status_label}`);
        emit('done', data.data);
    } catch (e) {
        const err = e as ApiError;
        toast.error(err.field('action') ?? err.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <template v-if="tenant.can('shipments.manage')">
        <Button
            v-for="(a, i) in list"
            :key="a.key"
            :size="size"
            :variant="i === 0 && a.key !== 'cancel' ? 'default' : 'outline'"
            :disabled="!a.allowed || busy"
            :title="a.reason ?? undefined"
            :data-testid="`shipment-action-${a.key}`"
            @click="start(a.key)"
        >
            {{ a.label }}
        </Button>
    </template>
    <Dialog :open="asking !== null" :title="asking ? (shipment.actions?.[asking]?.label ?? '') : ''" :description="shipment.tracking_number ?? undefined" @update:open="(v: boolean) => !v && (asking = null)">
        <label class="mb-1 block text-sm text-muted-foreground">{{ asking ? actionNeedsReason[asking] : '' }}</label>
        <Input v-model="reason" data-testid="shipment-action-reason" placeholder="Ví dụ: Khách hẹn giao hôm khác" @keydown.enter="asking && run(asking)" />
        <div class="mt-4 flex justify-end gap-2">
            <Button variant="outline" @click="asking = null">Đóng</Button>
            <Button :disabled="busy" data-testid="shipment-action-confirm" @click="asking && run(asking)">Xác nhận</Button>
        </div>
    </Dialog>
</template>
