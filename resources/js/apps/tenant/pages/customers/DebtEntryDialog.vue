<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';

/** Opening balance or adjustment of a customer's debt: a numbered document with its reason. */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customerId: string; kind: 'opening' | 'adjustment' }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const form = reactive({ direction: 'up', amount: '', reason: '', entry_date: '', due_date: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);
const opening = computed(() => props.kind === 'opening');
watch(open, (o) => {
    if (o) {
        Object.assign(form, { direction: 'up', amount: '', reason: opening.value ? 'Công nợ đầu kỳ khi bắt đầu dùng phần mềm' : '', entry_date: '', due_date: '' });
        error.value = null;
    }
});

async function save() {
    saving.value = true;
    error.value = null;
    const amount = Number(form.amount || 0) * (!opening.value && form.direction === 'down' ? -1 : 1);
    try {
        await api.post(`/customers/${props.customerId}/debt-entries`, {
            kind: props.kind, amount, reason: form.reason, entry_date: form.entry_date || null, due_date: form.due_date || null,
        });
        toast.success(opening.value ? 'Đã ghi công nợ đầu kỳ.' : 'Đã ghi phiếu điều chỉnh công nợ.');
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
    <Dialog
        v-model:open="open"
        :title="opening ? 'Nhập công nợ đầu kỳ' : 'Điều chỉnh công nợ'"
        :description="opening ? 'Số khách còn nợ từ trước khi dùng phần mềm. Mỗi khách chỉ có một chứng từ đầu kỳ.' : 'Tăng hoặc giảm công nợ ngoài đơn hàng (chiết khấu, bù trừ, sai sót). Chứng từ không sửa được, chỉ hủy kèm lý do.'"
    >
        <form id="debt-entry-form" class="grid gap-3 sm:grid-cols-2" data-testid="debt-entry-form" @submit.prevent="save">
            <FormField v-if="!opening" label="Loại điều chỉnh" for="de-direction">
                <Select id="de-direction" v-model="form.direction" :options="[{ value: 'up', label: 'Tăng công nợ' }, { value: 'down', label: 'Giảm công nợ' }]" data-testid="de-direction" />
            </FormField>
            <FormField label="Số tiền" for="de-amount" :error="error?.field('amount')" :class="opening ? 'sm:col-span-2' : ''">
                <Input id="de-amount" v-model="form.amount" type="number" min="1" step="any" required data-testid="de-amount" />
            </FormField>
            <FormField label="Ngày chứng từ" for="de-date" :error="error?.field('entry_date')"><Input id="de-date" v-model="form.entry_date" type="date" /></FormField>
            <FormField label="Hạn thanh toán" for="de-due" hint="Quá ngày này thì tính là nợ quá hạn." :error="error?.field('due_date')"><Input id="de-due" v-model="form.due_date" type="date" /></FormField>
            <FormField label="Lý do" for="de-reason" class="sm:col-span-2" :error="error?.field('reason') ?? error?.field('kind')">
                <Input id="de-reason" v-model="form.reason" maxlength="500" required data-testid="de-reason" />
            </FormField>
        </form>
        <p v-if="error && !error.field('amount') && !error.field('reason') && !error.field('kind')" class="mt-2 text-sm text-destructive">{{ error.message }}</p>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="debt-entry-form" :loading="saving" data-testid="de-save">Ghi chứng từ</Button>
        </template>
    </Dialog>
</template>
