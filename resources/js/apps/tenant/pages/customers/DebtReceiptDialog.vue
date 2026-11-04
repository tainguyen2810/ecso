<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatMoney } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';

/** One receipt from the customer: settles opening balances first, then the oldest unpaid orders. */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customerId: string; debt: number }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const form = reactive({ amount: '', method: 'cash', reference: '', note: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);
watch(open, (o) => {
    if (o) {
        Object.assign(form, { amount: String(props.debt), method: 'cash', reference: '', note: '' });
        error.value = null;
    }
});
const methods = Object.entries(paymentMethods).filter(([v]) => v !== 'cod').map(([value, label]) => ({ value, label }));

async function save() {
    saving.value = true;
    error.value = null;
    try {
        await api.post(`/customers/${props.customerId}/debt-receipts`, { ...form, reference: form.reference || null, note: form.note || null });
        toast.success('Đã thu nợ.');
        open.value = false;
        emit('saved');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Thu nợ khách hàng" :description="`Khách đang nợ ${formatMoney(debt)}. Tiền thu trả nợ đầu kỳ/điều chỉnh trước, rồi đến các đơn cũ nhất.`">
        <form id="debt-receipt-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Số tiền thu" for="dr-amount" :error="error?.field('amount')"><Input id="dr-amount" v-model="form.amount" type="number" min="1" step="any" required data-testid="dr-amount" /></FormField>
            <FormField label="Hình thức" for="dr-method"><Select id="dr-method" v-model="form.method" :options="methods" /></FormField>
            <FormField label="Mã giao dịch" for="dr-ref"><Input id="dr-ref" v-model="form.reference" maxlength="100" /></FormField>
            <FormField label="Ghi chú" for="dr-note"><Input id="dr-note" v-model="form.note" maxlength="500" /></FormField>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="debt-receipt-form" :loading="saving" data-testid="dr-save">Thu tiền</Button>
        </template>
    </Dialog>
</template>
