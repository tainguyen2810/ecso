<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Pencil, Plus, Trash2, UsersRound } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CustomersNav from './CustomersNav.vue';
import { priceTierLabels } from './labels';
import { paymentMethods } from '../orders/labels';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { CustomerGroup } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('customers.manage'));
const rows = ref<CustomerGroup[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

const dialog = ref(false);
const editing = ref<CustomerGroup | null>(null);
const form = reactive({ name: '', code: '', description: '', price_tier: 'retail', discount_percent: '0', payment_method: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        rows.value = (await api.get('/customer-groups')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function edit(g: CustomerGroup | null) {
    editing.value = g;
    error.value = null;
    Object.assign(form, {
        name: g?.name ?? '', code: g?.code ?? '', description: g?.description ?? '', price_tier: g?.price_tier ?? 'retail',
        discount_percent: g ? String(Number(g.discount_percent)) : '0', payment_method: g?.payment_method ?? '',
    });
    dialog.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        editing.value ? await api.put(`/customer-groups/${editing.value.id}`, body) : await api.post('/customer-groups', body);
        toast.success(editing.value ? 'Đã lưu nhóm.' : 'Đã thêm nhóm.');
        dialog.value = false;
        await load();
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

async function remove(g: CustomerGroup) {
    if (!window.confirm(`Xóa nhóm ${g.name}?`)) return;
    try {
        await api.delete(`/customer-groups/${g.id}`);
        toast.success('Đã xóa nhóm.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <PageHeader title="Khách hàng" description="Nhóm khách và điều khoản mua hàng của từng nhóm: giá áp dụng, chiết khấu, cách thanh toán quen dùng.">
        <Button v-if="canManage" data-testid="add-group" @click="edit(null)"><Plus /> Thêm nhóm</Button>
    </PageHeader>
    <CustomersNav />

    <Card>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 3" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="UsersRound" title="Chưa có nhóm khách hàng" description="Tạo nhóm như Khách lẻ, Khách sỉ, VIP để lọc khách, giao việc và ghi điều khoản mua hàng cho từng nhóm.">
            <Button v-if="canManage" @click="edit(null)"><Plus /> Thêm nhóm</Button>
        </EmptyState>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="group-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nhóm</th>
                        <th class="px-4 py-2.5 font-medium">Giá áp dụng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Chiết khấu</th>
                        <th class="px-4 py-2.5 font-medium">Thanh toán quen dùng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số khách</th>
                        <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                        <th v-if="canManage" class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="g in rows" :key="g.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ g.name }}</div>
                            <div class="text-xs text-muted-foreground"><span class="font-mono">{{ g.code }}</span><span v-if="g.description"> · {{ g.description }}</span></div>
                        </td>
                        <td class="px-4 py-3">{{ priceTierLabels[g.price_tier] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Number(g.discount_percent) ? `${Number(g.discount_percent)}%` : '—' }}</td>
                        <td class="px-4 py-3">{{ g.payment_method ? paymentMethods[g.payment_method] : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            <RouterLink :to="{ path: '/customers', query: { group: g.id } }" class="text-primary hover:underline">{{ formatNumber(g.customers_count) }}</RouterLink>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDate(g.created_at) }}</td>
                        <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
                            <Button variant="ghost" size="sm" :aria-label="`Sửa ${g.name}`" @click="edit(g)"><Pencil /></Button>
                            <Button variant="ghost" size="sm" :aria-label="`Xóa ${g.name}`" :disabled="!!g.customers_count" :title="g.customers_count ? 'Nhóm còn khách' : 'Xóa'" @click="remove(g)"><Trash2 /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>

    <Dialog v-model:open="dialog" :title="editing ? 'Sửa nhóm khách hàng' : 'Thêm nhóm khách hàng'">
        <form id="group-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Tên nhóm" for="g-name" :error="error?.field('name')"><Input id="g-name" v-model="form.name" required data-testid="g-name" /></FormField>
            <FormField label="Mã nhóm" for="g-code" :error="error?.field('code')" :hint="editing ? undefined : 'Để trống để tạo từ tên'"><Input id="g-code" v-model="form.code" /></FormField>
            <FormField label="Giá áp dụng" for="g-tier" :error="error?.field('price_tier')">
                <Select id="g-tier" v-model="form.price_tier" :options="Object.entries(priceTierLabels).map(([value, label]) => ({ value, label }))" />
            </FormField>
            <FormField label="Chiết khấu (%)" for="g-discount" :error="error?.field('discount_percent')"><Input id="g-discount" v-model="form.discount_percent" type="number" min="0" max="100" step="0.5" /></FormField>
            <FormField label="Thanh toán quen dùng" for="g-pay" class="sm:col-span-2">
                <Select id="g-pay" v-model="form.payment_method" :options="[{ value: '', label: 'Không đặt' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))]" />
            </FormField>
            <FormField label="Mô tả" for="g-desc" class="sm:col-span-2"><Input id="g-desc" v-model="form.description" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="dialog = false">Hủy</Button>
            <Button type="submit" form="group-form" :loading="saving" data-testid="g-save">Lưu</Button>
        </template>
    </Dialog>
</template>
