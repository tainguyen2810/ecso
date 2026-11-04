<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { supplierPaymentMethods } from './labels';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ supplierId: string; order?: { id: string; number: string } | null; suggested?: number }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const form = reactive({ method: 'bank_transfer', amount: '', reference: '', note: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);
let key = newIdempotencyKey();

watch(open, (v) => {
    if (!v) return;
    error.value = null;
    key = newIdempotencyKey();
    Object.assign(form, { method: 'bank_transfer', amount: props.suggested && props.suggested > 0 ? String(Math.round(props.suggested)) : '', reference: '', note: '' });
});

async function save() {
    saving.value = true;
    error.value = null;
    try {
        await api.post(`/suppliers/${props.supplierId}/payments`, {
            purchase_order_id: props.order?.id ?? null,
            method: form.method,
            amount: Number(form.amount),
            reference: form.reference || null,
            note: form.note || null,
        }, idempotent(key));
        toast.success(`Đã ghi chi ${formatMoney(form.amount)}.`);
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
    <Dialog v-model:open="open" title="Chi tiền nhà cung cấp" :description="order ? `Thanh toán cho đơn ${order.number}. Còn nợ ${formatMoney(suggested ?? 0)}.` : 'Thanh toán chung (trả nợ cũ hoặc trả trước), không gắn với đơn nhập nào.'">
        <form id="sp-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Hình thức">
                <Select v-model="form.method" :options="Object.entries(supplierPaymentMethods).map(([value, label]) => ({ value, label }))" />
            </FormField>
            <FormField label="Số tiền" :error="error?.field('amount')"><Input v-model="form.amount" type="number" min="1" required data-testid="sp-amount" /></FormField>
            <FormField label="Số chứng từ / UNC" class="sm:col-span-2"><Input v-model="form.reference" /></FormField>
            <FormField label="Ghi chú" class="sm:col-span-2"><Input v-model="form.note" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="sp-form" :loading="saving" data-testid="sp-save">Ghi phiếu chi</Button>
        </template>
    </Dialog>
</template>
