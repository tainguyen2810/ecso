<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Pencil, Plus, Star, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import InventoryNav from './InventoryNav.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Warehouse } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('settings.manage'));

const warehouses = ref<Warehouse[]>([]);
const limit = ref<number | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const atLimit = computed(() => limit.value !== null && warehouses.value.length >= limit.value);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/warehouses');
        warehouses.value = data.data;
        limit.value = data.meta.limit;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const open = ref(false);
const editing = ref<Warehouse | null>(null);
const form = reactive({ code: '', name: '', address: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

function openCreate() {
    editing.value = null;
    Object.assign(form, { code: '', name: '', address: '' });
    error.value = null;
    open.value = true;
}
function openEdit(w: Warehouse) {
    editing.value = w;
    Object.assign(form, { code: w.code, name: w.name, address: w.address ?? '' });
    error.value = null;
    open.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        if (editing.value) await api.patch(`/warehouses/${editing.value.id}`, { name: form.name, address: form.address || null });
        else await api.post('/warehouses', { code: form.code, name: form.name, address: form.address || null });
        toast.success(editing.value ? 'Đã cập nhật kho' : `Đã tạo kho ${form.code.toUpperCase()}`);
        open.value = false;
        await load();
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

async function update(w: Warehouse, payload: Record<string, unknown>, message: string) {
    try {
        await api.patch(`/warehouses/${w.id}`, payload);
        toast.success(message);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <PageHeader title="Kho hàng" description="Danh sách kho vật lý. Mỗi SKU có tồn riêng theo từng kho.">
        <Button v-if="canManage" :disabled="atLimit" data-testid="new-warehouse" @click="openCreate"><Plus /> Thêm kho</Button>
    </PageHeader>
    <InventoryNav />

    <div v-if="atLimit && canManage" class="mb-4 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
        Gói hiện tại cho phép tối đa <b>{{ limit }}</b> kho. <RouterLink to="/subscription" class="font-medium text-primary hover:underline">Xem gói dịch vụ</RouterLink> để thêm kho.
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-36" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="warehouse-list">
        <Card v-for="w in warehouses" :key="w.id" class="flex flex-col p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-md bg-secondary text-primary"><WarehouseIcon class="size-5" /></span>
                    <div>
                        <p class="font-semibold">{{ w.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">{{ w.code }}</p>
                    </div>
                </div>
                <div class="flex gap-1">
                    <Badge v-if="w.is_default" tone="default"><Star class="mr-1 size-3" /> Mặc định</Badge>
                    <Badge :tone="w.status === 'active' ? 'success' : 'muted'">{{ w.status === 'active' ? 'Hoạt động' : 'Ngừng' }}</Badge>
                </div>
            </div>
            <p class="mt-3 min-h-5 text-sm text-muted-foreground">{{ w.address || 'Chưa có địa chỉ' }}</p>
            <div class="mt-4 grid grid-cols-2 gap-3 rounded-md bg-muted/50 p-3 text-sm">
                <div><p class="text-xs text-muted-foreground">SKU có tồn</p><p class="font-semibold tabular-nums">{{ formatNumber(w.sku_count) }}</p></div>
                <div><p class="text-xs text-muted-foreground">Tổng tồn</p><p class="font-semibold tabular-nums">{{ formatNumber(w.on_hand) }}</p></div>
            </div>
            <div v-if="canManage" class="mt-4 flex flex-wrap gap-2 border-t pt-3">
                <Button variant="ghost" size="sm" @click="openEdit(w)"><Pencil /> Sửa</Button>
                <Button v-if="!w.is_default && w.status === 'active'" variant="ghost" size="sm" @click="update(w, { is_default: true }, `${w.code} là kho mặc định`)"><Star /> Đặt mặc định</Button>
                <Button v-if="!w.is_default" variant="ghost" size="sm" class="ml-auto" @click="update(w, { status: w.status === 'active' ? 'inactive' : 'active' }, 'Đã đổi trạng thái kho')">
                    {{ w.status === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt lại' }}
                </Button>
            </div>
        </Card>
    </div>

    <Dialog v-model:open="open" :title="editing ? `Sửa kho ${editing.code}` : 'Thêm kho'" description="Mã kho dùng trên chứng từ và không đổi được sau khi tạo.">
        <form id="warehouse-form" class="space-y-4" @submit.prevent="save">
            <FormField label="Mã kho" for="wh-code" :error="error?.field('code')" hint="Chữ, số, - và _ (ví dụ HCM, HN-01)">
                <Input id="wh-code" v-model="form.code" :disabled="!!editing" required maxlength="32" class="uppercase" />
            </FormField>
            <FormField label="Tên kho" for="wh-name" :error="error?.field('name')"><Input id="wh-name" v-model="form.name" required maxlength="255" /></FormField>
            <FormField label="Địa chỉ" for="wh-address" :error="error?.field('address')"><Input id="wh-address" v-model="form.address" maxlength="255" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="warehouse-form" :loading="saving">{{ editing ? 'Lưu' : 'Tạo kho' }}</Button>
        </template>
    </Dialog>
</template>
