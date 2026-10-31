<script setup lang="ts">
import { reactive, ref } from 'vue';
import PageHeader from '../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

const tenant = useTenantStore();
const toast = useToast();

const form = reactive({ name: tenant.current?.name ?? '', timezone: tenant.current?.timezone ?? 'Asia/Ho_Chi_Minh' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

const timezones = ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Jakarta', 'Asia/Manila', 'UTC'].map((z) => ({ value: z, label: z }));

async function save() {
    saving.value = true;
    error.value = null;
    try {
        await api.patch('/tenant', form);
        await Promise.all([tenant.select(tenant.current!.id), tenant.fetchTenants()]);
        toast.success('Đã lưu cài đặt');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageHeader title="Cài đặt" description="Thông tin chung của doanh nghiệp." />
    <Card class="max-w-2xl" title="Thông tin doanh nghiệp">
        <form class="space-y-4 p-5" @submit.prevent="save">
            <FormField label="Tên doanh nghiệp" for="name" :error="error?.field('name')">
                <Input id="name" v-model="form.name" required />
            </FormField>
            <FormField label="Múi giờ" for="timezone" hint="Dùng để hiển thị thời gian đơn hàng và báo cáo." :error="error?.field('timezone')">
                <Select id="timezone" v-model="form.timezone" :options="timezones" />
            </FormField>
            <FormField label="Tiền tệ" for="currency" hint="MVP chỉ hỗ trợ VND.">
                <Input id="currency" :model-value="tenant.current?.currency" disabled />
            </FormField>
            <div class="flex justify-end"><Button type="submit" :loading="saving">Lưu thay đổi</Button></div>
        </form>
    </Card>
</template>
