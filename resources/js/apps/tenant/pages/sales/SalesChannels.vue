<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { Info, Pencil, Plus, Store, Trash2, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import SettingsShell from '../settings/SettingsShell.vue';
import ChannelMark from '../reports/ChannelMark.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { channelTypes } from '../orders/labels';
import EzChatLinkCard from './EzChatLinkCard.vue';
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
        warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.purpose !== 'defective');
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

/** Turn a channel on or off straight from its card. */
async function toggle(c: SalesChannel) {
    try {
        await api.put(`/sales-channels/${c.id}`, { name: c.name, type: c.type, warehouse_id: c.warehouse?.id ?? null, is_active: !c.is_active });
        c.is_active = !c.is_active;
        toast.success(c.is_active ? `Đã bật kênh ${c.name}.` : `Đã tắt kênh ${c.name}, kênh không hiện khi tạo đơn.`);
    } catch (e) {
        toast.error((e as ApiError).message);
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
    <SettingsShell wide title="Kênh bán riêng" description="Nơi anh tự bán ngoài sàn: quầy, cửa hàng, Facebook, Zalo, website. Mỗi kênh có thể xuất từ một kho riêng.">
        <template #actions>
            <button
                v-if="tenant.can('settings.manage')"
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700"
                data-testid="add-channel"
                @click="edit(null)"
            >
                <Plus class="size-4" /> Thêm kênh
            </button>
        </template>
        <EzChatLinkCard :channels="rows" @changed="load" />
        <div v-if="loading" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-48 rounded-[14px]" /></div>
        <div v-else-if="!rows.length" class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <EmptyState :icon="Store" title="Chưa có kênh bán" description="Thêm kênh để tạo đơn bán ngoài sàn." />
        </div>
        <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" data-testid="channel-table">
            <div
                v-for="c in rows"
                :key="c.id"
                :class="cn('flex flex-col rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]', !c.is_active && 'opacity-70')"
            >
                <div class="flex items-start gap-3.5">
                    <ChannelMark :kind="c.type" :name="c.name" size="lg" class="!size-11" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[16px] font-semibold text-slate-900">{{ c.name }}</p>
                        <p class="text-[13px] text-slate-500">{{ channelTypes[c.type] ?? c.type }}<template v-if="!c.is_active"> · đã tắt</template></p>
                    </div>
                    <button
                        v-if="tenant.can('settings.manage')"
                        type="button"
                        role="switch"
                        :aria-checked="c.is_active"
                        :aria-label="c.is_active ? `Tắt kênh ${c.name}` : `Bật kênh ${c.name}`"
                        :class="cn('relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors', c.is_active ? 'bg-blue-600' : 'bg-slate-200')"
                        @click="toggle(c)"
                    >
                        <span :class="cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', c.is_active ? 'left-[22px]' : 'left-0.5')" />
                    </button>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 rounded-[12px] bg-slate-50 p-3.5">
                    <div><p class="text-[12px] text-slate-500">Đơn 30 ngày</p><p class="text-[18px] font-bold tabular-nums text-slate-900">{{ formatNumber(c.orders_30d ?? 0) }}</p></div>
                    <div><p class="text-[12px] text-slate-500">Doanh thu 30 ngày</p><p class="whitespace-nowrap text-[18px] font-bold tabular-nums text-slate-900">{{ formatMoney(c.revenue_30d ?? 0) }}</p></div>
                </div>
                <div class="mt-3.5 flex items-center gap-2 text-[13px]">
                    <span class="flex min-w-0 flex-1 items-center gap-1.5 truncate text-slate-500"><WarehouseIcon class="size-4 shrink-0" /> Xuất từ {{ c.warehouse ? c.warehouse.name : 'kho mặc định' }}</span>
                    <span class="text-slate-400">{{ formatNumber(c.orders_count ?? 0) }} đơn tất cả</span>
                    <template v-if="tenant.can('settings.manage')">
                        <button v-if="!c.orders_count" type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-red-50 hover:text-red-600" title="Xóa" :aria-label="`Xóa ${c.name}`" @click="remove(c)">
                            <Trash2 class="size-4" />
                        </button>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-[9px] border border-slate-200 px-3 py-1.5 text-slate-600 hover:border-blue-300 hover:text-blue-700" :aria-label="`Sửa ${c.name}`" @click="edit(c)">
                            <Pencil class="size-3.5" /> Sửa
                        </button>
                    </template>
                </div>
            </div>
        </div>
        <p v-if="rows.length" class="mt-4 flex items-center gap-2.5 rounded-[14px] border border-dashed border-slate-300 bg-white/60 px-5 py-4 text-[14px] text-slate-500">
            <Info class="size-4 shrink-0" /> Tắt một kênh để ẩn khỏi màn tạo đơn; đơn cũ của kênh vẫn giữ trong báo cáo. Gian hàng Shopee, TikTok Shop nằm ở Kết nối gian hàng.
        </p>
    </SettingsShell>

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
