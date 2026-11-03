<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import type { Customer } from '@/shared/types';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customer?: Customer | null }>();
const emit = defineEmits<{ saved: [customer: Customer] }>();
const toast = useToast();

const form = reactive({ name: '', phone: '', email: '', address: '', group: '', note: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

watch(open, (v) => {
    if (!v) return;
    error.value = null;
    const c = props.customer;
    Object.assign(form, { name: c?.name ?? '', phone: c?.phone ?? '', email: c?.email ?? '', address: c?.address ?? '', group: c?.group ?? '', note: c?.note ?? '' });
});

async function save() {
    saving.value = true;
    error.value = null;
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        const { data } = props.customer ? await api.put(`/customers/${props.customer.id}`, body) : await api.post('/customers', body);
        toast.success(props.customer ? 'Đã lưu khách hàng.' : `Đã thêm khách ${data.data.code}.`);
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
    <Dialog v-model:open="open" :title="customer ? 'Sửa khách hàng' : 'Thêm khách hàng'" description="Số điện thoại và địa chỉ được mã hóa khi lưu. Mỗi số điện thoại chỉ thuộc một khách.">
        <form id="customer-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Tên khách" for="c-name" class="sm:col-span-2" :error="error?.field('name')"><Input id="c-name" v-model="form.name" required data-testid="c-name" /></FormField>
            <FormField label="Số điện thoại" for="c-phone" :error="error?.field('phone')"><Input id="c-phone" v-model="form.phone" inputmode="tel" data-testid="c-phone" /></FormField>
            <FormField label="Email" for="c-email" :error="error?.field('email')"><Input id="c-email" v-model="form.email" type="email" /></FormField>
            <FormField label="Địa chỉ" for="c-address" class="sm:col-span-2"><Input id="c-address" v-model="form.address" /></FormField>
            <FormField label="Nhóm khách" for="c-group"><Input id="c-group" v-model="form.group" placeholder="VIP, Sỉ, Lẻ…" /></FormField>
            <FormField label="Ghi chú" for="c-note"><Input id="c-note" v-model="form.note" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="customer-form" :loading="saving" data-testid="c-save">Lưu</Button>
        </template>
    </Dialog>
</template>
