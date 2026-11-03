<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { Pencil, Plus, Store, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { channelTypes } from '../orders/labels';
import type { SalesChannel, Warehouse } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const rows = ref<SalesChannel[]>([]);
const warehouses = ref<Warehouse[]>([]);
const loading = ref(true);
const open = ref(false);
const editing = ref<SalesChannel | null>(null);
const form = reactive({ name: '', type: 'store', warehouse_id: '', is_active: true });
const saving = ref(false);
const error = ref<ApiError | null>(null);

async function load() {
    loading.value = true;
    try {
        const [c, w] = await Promise.all([api.get('/sales-channels'), api.get('/warehouses')]);
        rows.value = c.data.data;
        warehouses.value = w.data.data;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function edit(c: SalesChannel | null) {
    editing.value = c;
    error.value = null;
    Object.assign(form, { name: c?.name ?? '', type: c?.type ?? 'store', warehouse_id: c?.warehouse?.id ?? '', is_active: c?.is_active ?? true });
    open.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    const body = { ...form, warehouse_id: form.warehouse_id || null };
    try {
        if (editing.value) await api.put(`/sales-channels/${editing.value.id}`, body);
        else await api.post('/sales-channels', body);
        toast.success('Đã lưu kênh bán.');
        open.value = false;
        await load();
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

async function remove(c: SalesChannel) {
    try {
        await api.delete(`/sales-channels/${c.id}`);
        toast.success('Đã xóa kênh.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <PageHeader title="Kênh bán riêng" description="Nơi bạn tự bán ngoài sàn: quầy, cửa hàng, Facebook, Zalo, website. Mỗi kênh có thể xuất từ một kho riêng.">
        <Button v-if="tenant.can('settings.manage')" data-testid="add-channel" @click="edit(null)"><Plus /> Thêm kênh</Button>
    </PageHeader>
    <Card>
        <div v-if="loading" class="space-y-3 p-4"><Skeleton v-for="i in 3" :key="i" class="h-10" /></div>
        <EmptyState v-else-if="!rows.length" :icon="Store" title="Chưa có kênh bán" description="Thêm kênh để tạo đơn bán ngoài sàn." />
        <table v-else class="w-full text-sm" data-testid="channel-table">
            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr><th class="px-4 py-2.5 font-medium">Tên kênh</th><th class="px-4 py-2.5 font-medium">Loại</th><th class="px-4 py-2.5 font-medium">Kho xuất mặc định</th><th class="px-4 py-2.5 text-right font-medium">Số đơn</th><th class="px-4 py-2.5 font-medium">Trạng thái</th><th class="px-4 py-2.5" /></tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="c in rows" :key="c.id">
                    <td class="px-4 py-3 font-medium">{{ c.name }}</td>
                    <td class="px-4 py-3">{{ channelTypes[c.type] ?? c.type }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ c.warehouse ? `${c.warehouse.code} · ${c.warehouse.name}` : 'Kho mặc định' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(c.orders_count ?? 0) }}</td>
                    <td class="px-4 py-3"><Badge :tone="c.is_active ? 'success' : 'muted'">{{ c.is_active ? 'Đang bán' : 'Đã tắt' }}</Badge></td>
                    <td class="px-4 py-3 text-right">
                        <template v-if="tenant.can('settings.manage')">
                            <Button variant="ghost" size="icon" class="size-8" title="Sửa" @click="edit(c)"><Pencil /></Button>
                            <Button v-if="!c.orders_count" variant="ghost" size="icon" class="size-8" title="Xóa" @click="remove(c)"><Trash2 /></Button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </Card>

    <Dialog v-model:open="open" :title="editing ? 'Sửa kênh bán' : 'Thêm kênh bán'">
        <form id="channel-form" class="space-y-3" @submit.prevent="save">
            <FormField label="Tên kênh" for="ch-name" :error="error?.field('name')"><Input id="ch-name" v-model="form.name" required placeholder="Fanpage ABC, Cửa hàng Q1…" data-testid="ch-name" /></FormField>
            <FormField label="Loại" for="ch-type"><Select id="ch-type" v-model="form.type" :options="Object.entries(channelTypes).map(([value, label]) => ({ value, label }))" /></FormField>
            <FormField label="Kho xuất mặc định" for="ch-wh"><Select id="ch-wh" v-model="form.warehouse_id" :options="[{ value: '', label: 'Kho mặc định của doanh nghiệp' }, ...warehouses.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))]" /></FormField>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="size-4" /> Đang bán (tắt để ẩn khỏi màn tạo đơn)</label>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="channel-form" :loading="saving" data-testid="ch-save">Lưu</Button>
        </template>
    </Dialog>
</template>
