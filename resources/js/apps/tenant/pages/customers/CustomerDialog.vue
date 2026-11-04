<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { customerSources, customerTypes } from './labels';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import type { Customer, CustomerGroup } from '@/shared/types';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customer?: Customer | null }>();
const emit = defineEmits<{ saved: [customer: Customer] }>();
const toast = useToast();

const empty = { name: '', code: '', phone: '', email: '', address: '', group_id: '', assigned_user_id: '', birthday: '', gender: '', tax_code: '', status: 'active', note: '', payment_terms_days: '', credit_limit: '', type: 'individual', source: '', invoice_name: '', invoice_address: '', invoice_email: '' };
const form = reactive({ ...empty });
const tags = ref<string[]>([]);
const tagInput = ref('');
const groups = ref<CustomerGroup[]>([]);
const staff = ref<{ id: string; name: string }[]>([]);
const knownTags = ref<string[]>([]);
const loaded = ref(false);
const saving = ref(false);
const error = ref<ApiError | null>(null);

watch(open, async (v) => {
    if (!v) return;
    error.value = null;
    const c = props.customer;
    Object.assign(
        form,
        empty,
        c
            ? {
                  name: c.name, code: c.code, phone: c.phone ?? '', email: c.email ?? '', address: c.address ?? '', group_id: c.group?.id ?? '',
                  assigned_user_id: c.assignee?.id ?? '', birthday: c.birthday ?? '', gender: c.gender ?? '', tax_code: c.tax_code ?? '', status: c.status, note: c.note ?? '',
                  payment_terms_days: c.payment_terms_days != null ? String(c.payment_terms_days) : '', credit_limit: c.credit_limit != null ? String(Number(c.credit_limit)) : '',
                  type: c.type ?? 'individual', source: c.source ?? '', invoice_name: c.invoice?.name ?? '', invoice_address: c.invoice?.address ?? '', invoice_email: c.invoice?.email ?? '',
              }
            : {},
    );
    tags.value = [...(c?.tags ?? [])];
    tagInput.value = '';
    if (!loaded.value) {
        const [g, s, t] = await Promise.all([api.get('/customer-groups'), api.get('/customers/staff'), api.get('/customers/tags')]);
        groups.value = g.data.data;
        staff.value = s.data.data;
        knownTags.value = t.data.data;
        loaded.value = true;
    }
});

function addTag(raw = tagInput.value) {
    for (const t of raw.split(',').map((s) => s.trim()).filter(Boolean)) {
        if (!tags.value.some((x) => x.toLowerCase() === t.toLowerCase()) && tags.value.length < 20) tags.value.push(t.slice(0, 40));
    }
    tagInput.value = '';
}

async function save() {
    addTag();
    saving.value = true;
    error.value = null;
    const body: Record<string, unknown> = { ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])), tags: tags.value };
    if (!props.customer) delete body.status;
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
    <Dialog v-model:open="open" size="lg" :title="customer ? 'Sửa khách hàng' : 'Thêm khách hàng'" description="Số điện thoại và địa chỉ được mã hóa khi lưu. Mỗi số điện thoại chỉ thuộc một khách.">
        <form id="customer-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Loại khách" for="c-type" :error="error?.field('type')">
                <Select id="c-type" v-model="form.type" :options="Object.entries(customerTypes).map(([value, label]) => ({ value, label }))" data-testid="c-type" />
            </FormField>
            <FormField label="Nguồn khách" for="c-source" :error="error?.field('source')">
                <Select id="c-source" v-model="form.source" :options="[{ value: '', label: 'Không rõ' }, ...Object.entries(customerSources).map(([value, label]) => ({ value, label }))]" />
            </FormField>
            <FormField label="Tên khách" for="c-name" :error="error?.field('name')"><Input id="c-name" v-model="form.name" required data-testid="c-name" /></FormField>
            <FormField label="Mã khách" for="c-code" :error="error?.field('code')" :hint="customer ? undefined : 'Để trống để hệ thống tự đánh mã KH-…'"><Input id="c-code" v-model="form.code" data-testid="c-code" /></FormField>
            <FormField label="Số điện thoại" for="c-phone" :error="error?.field('phone')"><Input id="c-phone" v-model="form.phone" inputmode="tel" data-testid="c-phone" /></FormField>
            <FormField label="Email" for="c-email" :error="error?.field('email')"><Input id="c-email" v-model="form.email" type="email" /></FormField>
            <FormField label="Địa chỉ" for="c-address" class="sm:col-span-2" :error="error?.field('address')"><Input id="c-address" v-model="form.address" placeholder="Số nhà, đường, phường/xã, tỉnh/thành phố" /></FormField>
            <FormField label="Nhóm khách hàng" for="c-group" :error="error?.field('group_id')">
                <Select id="c-group" v-model="form.group_id" data-testid="c-group" :options="[{ value: '', label: 'Chưa xếp nhóm' }, ...groups.filter((g) => g.is_active !== false || g.id === props.customer?.group?.id).map((g) => ({ value: g.id, label: g.is_active === false ? `${g.name} (ngừng sử dụng)` : g.name }))]" />
            </FormField>
            <FormField label="Nhân viên phụ trách" for="c-staff" :error="error?.field('assigned_user_id')">
                <Select id="c-staff" v-model="form.assigned_user_id" :options="[{ value: '', label: 'Chưa giao' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]" />
            </FormField>
            <FormField label="Ngày sinh" for="c-birthday" :error="error?.field('birthday')"><Input id="c-birthday" v-model="form.birthday" type="date" /></FormField>
            <FormField label="Giới tính" for="c-gender">
                <Select id="c-gender" v-model="form.gender" :options="[{ value: '', label: 'Không rõ' }, { value: 'male', label: 'Nam' }, { value: 'female', label: 'Nữ' }, { value: 'other', label: 'Khác' }]" />
            </FormField>
            <FormField label="Mã số thuế" for="c-tax" :error="error?.field('tax_code')"><Input id="c-tax" v-model="form.tax_code" placeholder="Khi khách cần xuất hóa đơn" /></FormField>
            <FormField label="Hạn thanh toán (ngày)" for="c-terms" :error="error?.field('payment_terms_days')" hint="Để trống thì theo nhóm khách."><Input id="c-terms" v-model="form.payment_terms_days" type="number" min="0" max="365" data-testid="c-terms" /></FormField>
            <FormField label="Hạn mức nợ" for="c-credit" :error="error?.field('credit_limit')" hint="Để trống thì theo nhóm khách."><Input id="c-credit" v-model="form.credit_limit" type="number" min="0" step="any" data-testid="c-credit" /></FormField>
            <FormField v-if="customer" label="Trạng thái" for="c-status">
                <Select id="c-status" v-model="form.status" :options="[{ value: 'active', label: 'Đang giao dịch' }, { value: 'inactive', label: 'Ngừng giao dịch' }]" />
            </FormField>
            <details class="rounded-md border px-3 py-2 sm:col-span-2" :open="form.type === 'company' || !!form.invoice_name">
                <summary class="cursor-pointer text-sm font-medium">Thông tin xuất hóa đơn</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <FormField label="Tên đơn vị / người mua trên hóa đơn" for="c-inv-name" class="sm:col-span-2" :error="error?.field('invoice_name')"><Input id="c-inv-name" v-model="form.invoice_name" maxlength="200" data-testid="c-invoice-name" /></FormField>
                    <FormField label="Địa chỉ xuất hóa đơn" for="c-inv-address" class="sm:col-span-2" :error="error?.field('invoice_address')"><Input id="c-inv-address" v-model="form.invoice_address" maxlength="500" /></FormField>
                    <FormField label="Email nhận hóa đơn" for="c-inv-email" :error="error?.field('invoice_email')"><Input id="c-inv-email" v-model="form.invoice_email" type="email" maxlength="150" /></FormField>
                </div>
            </details>
            <FormField label="Tags" for="c-tags" class="sm:col-span-2" :error="error?.field('tags')">
                <div class="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-card px-2 py-1 shadow-xs focus-within:ring-2 focus-within:ring-ring">
                    <span v-for="(t, i) in tags" :key="t" class="inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-xs">
                        {{ t }}
                        <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="`Bỏ tag ${t}`" @click="tags.splice(i, 1)"><X class="size-3" /></button>
                    </span>
                    <input
                        id="c-tags"
                        v-model="tagInput"
                        list="customer-tag-suggestions"
                        class="h-7 min-w-32 flex-1 bg-transparent text-sm outline-none"
                        placeholder="Gõ tag rồi nhấn Enter"
                        data-testid="c-tags"
                        @keydown.enter.prevent="addTag()"
                        @keydown.,.prevent="addTag()"
                        @blur="addTag()"
                    />
                    <datalist id="customer-tag-suggestions"><option v-for="t in knownTags.filter((k) => !tags.includes(k))" :key="t" :value="t" /></datalist>
                </div>
            </FormField>
            <FormField label="Ghi chú" for="c-note" class="sm:col-span-2"><Input id="c-note" v-model="form.note" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="customer-form" :loading="saving" data-testid="c-save">Lưu</Button>
        </template>
    </Dialog>
</template>
