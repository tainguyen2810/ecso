<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { CARRIERS } from '@/shared/lib/geo';
import { useToast } from '@/shared/composables/useToast';
import type { ShipmentData } from './shipments';

/** "Sửa vận đơn": carrier and tracking number (orders the shop ships itself), parcels, weight, estimated fee. */
const props = defineProps<{ shipment: ShipmentData }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [shipment: ShipmentData] }>();
const toast = useToast();

const form = reactive({ carrier: '', tracking_number: '', packages: '1', weight_g: '', fee_estimated: '' });
const errors = ref<Record<string, string | undefined>>({});
const busy = ref(false);
const ownParcel = () => props.shipment.source !== 'marketplace';

watch(open, (v) => {
    if (!v) return;
    errors.value = {};
    Object.assign(form, {
        carrier: props.shipment.carrier_name ?? '',
        tracking_number: props.shipment.tracking_number ?? '',
        packages: String(props.shipment.packages ?? 1),
        weight_g: props.shipment.weight_g ? String(props.shipment.weight_g) : '',
        fee_estimated: props.shipment.fee_estimated ? String(Math.round(Number(props.shipment.fee_estimated))) : '',
    });
});

async function save() {
    busy.value = true;
    errors.value = {};
    try {
        const body: Record<string, unknown> = {
            packages: Number(form.packages) || 1,
            weight_g: form.weight_g ? Number(form.weight_g) : null,
            fee_estimated: form.fee_estimated ? Number(form.fee_estimated) : null,
        };
        if (ownParcel()) Object.assign(body, { carrier: form.carrier || null, tracking_number: form.tracking_number || null });
        const { data } = await api.put(`/shipments/${props.shipment.id}`, body);
        toast.success('Đã lưu vận đơn');
        open.value = false;
        emit('saved', data.data);
    } catch (e) {
        const err = e as ApiError;
        errors.value = { tracking_number: err.field('tracking_number'), carrier: err.field('carrier'), weight_g: err.field('weight_g') };
        if (!Object.values(errors.value).some(Boolean)) toast.error(err.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Sửa vận đơn" :description="ownParcel() ? 'Sửa khi gõ nhầm mã hoặc nhầm hãng. Mã vận đơn trên đơn hàng đổi theo.' : 'Đơn sàn: mã vận đơn do sàn cấp, chỉ sửa được số kiện, khối lượng, phí ước tính.'">
        <div class="grid gap-3 sm:grid-cols-2">
            <label v-if="ownParcel()" class="space-y-1 text-sm">
                <span class="text-muted-foreground">Hãng vận chuyển</span>
                <Input v-model="form.carrier" list="shipment-carriers" data-testid="shipment-edit-carrier" />
                <datalist id="shipment-carriers"><option v-for="c in CARRIERS" :key="c" :value="c" /><option value="Tự giao" /></datalist>
                <span v-if="errors.carrier" class="text-xs text-destructive">{{ errors.carrier }}</span>
            </label>
            <label v-if="ownParcel()" class="space-y-1 text-sm">
                <span class="text-muted-foreground">Mã vận đơn</span>
                <Input v-model="form.tracking_number" data-testid="shipment-edit-tracking" />
                <span v-if="errors.tracking_number" class="text-xs text-destructive">{{ errors.tracking_number }}</span>
            </label>
            <label class="space-y-1 text-sm"><span class="text-muted-foreground">Số kiện</span><Input v-model="form.packages" type="number" min="1" /></label>
            <label class="space-y-1 text-sm">
                <span class="text-muted-foreground">Khối lượng (g)</span><Input v-model="form.weight_g" type="number" min="1" data-testid="shipment-edit-weight" />
                <span v-if="errors.weight_g" class="text-xs text-destructive">{{ errors.weight_g }}</span>
            </label>
            <label class="space-y-1 text-sm sm:col-span-2">
                <span class="text-muted-foreground">Phí ship ước tính (₫)</span><Input v-model="form.fee_estimated" type="number" min="0" />
                <span class="text-[11px] text-muted-foreground">Chỉ để xem. Phí thật vào sổ khi đối soát COD hoặc lập phiếu chi.</span>
            </label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :disabled="busy" data-testid="shipment-edit-save" @click="save">Lưu</Button>
        </div>
    </Dialog>
</template>
