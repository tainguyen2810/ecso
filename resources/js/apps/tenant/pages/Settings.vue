<script setup lang="ts">
import { reactive, ref } from 'vue';
import PageHeader from '../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { defaultInvoiceSize, invoiceSizes, setDefaultInvoiceSize } from './orders/invoice';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

const tenant = useTenantStore();
const toast = useToast();

const form = reactive({ name: tenant.current?.name ?? '', timezone: tenant.current?.timezone ?? 'Asia/Ho_Chi_Minh' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

const printSize = ref(defaultInvoiceSize(tenant.current?.id));
function savePrintSize() {
    setDefaultInvoiceSize(tenant.current?.id, printSize.value);
    toast.success('Đã lưu mẫu in mặc định.');
}

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
    <div class="max-w-2xl space-y-5">
    <Card title="Thông tin doanh nghiệp">
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

    <Card title="Mẫu in" description="Mẫu dùng mặc định khi in hóa đơn bán hàng. Lưu trên trình duyệt này." data-testid="print-settings">
        <div class="grid gap-3 p-5 sm:grid-cols-2">
            <label v-for="s in invoiceSizes" :key="s.value" :class="cn('flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 text-sm hover:bg-muted/40', printSize === s.value && 'border-primary bg-blue-50/70 hover:bg-blue-50/70')">
                <input v-model="printSize" type="radio" :value="s.value" class="mt-0.5" @change="savePrintSize" />
                <span><span class="font-medium">{{ s.label }}</span><span class="block text-xs text-muted-foreground">{{ s.hint }}</span></span>
            </label>
        </div>
    </Card>
    </div>
</template>
