<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import type { Supplier } from '@/shared/types';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ supplier?: Supplier | null }>();
const emit = defineEmits<{ saved: [supplier: Supplier] }>();
const toast = useToast();

const form = reactive({ name: '', contact_name: '', phone: '', email: '', address: '', tax_code: '', payment_terms_days: '' as string | number, note: '', is_active: true });
const saving = ref(false);
const error = ref<ApiError | null>(null);

watch(open, (v) => {
    if (!v) return;
    error.value = null;
    const s = props.supplier;
    Object.assign(form, {
        name: s?.name ?? '',
        contact_name: s?.contact_name ?? '',
        phone: s?.phone ?? '',
        email: s?.email ?? '',
        address: s?.address ?? '',
        tax_code: s?.tax_code ?? '',
        payment_terms_days: s?.payment_terms_days ?? '',
        note: s?.note ?? '',
        is_active: s?.is_active ?? true,
    });
});

async function save() {
    saving.value = true;
    error.value = null;
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        const { data } = props.supplier ? await api.put(`/suppliers/${props.supplier.id}`, body) : await api.post('/suppliers', body);
        toast.success(props.supplier ? 'Đã lưu nhà cung cấp.' : `Đã thêm ${data.data.code}.`);
        open.value = false;
        emit('saved', data.data);
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="supplier ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp'">
        <form id="supplier-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Tên nhà cung cấp" for="s-name" class="sm:col-span-2" :error="error?.field('name')"><Input id="s-name" v-model="form.name" required data-testid="s-name" /></FormField>
            <FormField label="Người liên hệ" for="s-contact"><Input id="s-contact" v-model="form.contact_name" /></FormField>
            <FormField label="Số điện thoại" for="s-phone" :error="error?.field('phone')"><Input id="s-phone" v-model="form.phone" inputmode="tel" data-testid="s-phone" /></FormField>
            <FormField label="Email" for="s-email" :error="error?.field('email')"><Input id="s-email" v-model="form.email" type="email" /></FormField>
            <FormField label="Mã số thuế" for="s-tax"><Input id="s-tax" v-model="form.tax_code" /></FormField>
            <FormField label="Hạn thanh toán (ngày sau khi nhận hàng)" for="s-terms" :error="error?.field('payment_terms_days')"><Input id="s-terms" v-model="form.payment_terms_days" type="number" min="0" max="365" placeholder="Để trống: không tính quá hạn" data-testid="s-terms" /></FormField>
            <FormField label="Địa chỉ" for="s-address" class="sm:col-span-2"><Input id="s-address" v-model="form.address" /></FormField>
            <FormField label="Ghi chú" for="s-note" class="sm:col-span-2"><Input id="s-note" v-model="form.note" /></FormField>
            <label v-if="supplier" class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.is_active" type="checkbox" class="size-4" /> Đang giao dịch</label>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="supplier-form" :loading="saving" data-testid="s-save">Lưu</Button>
        </template>
    </Dialog>
</template>
