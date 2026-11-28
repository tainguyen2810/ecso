<script setup lang="ts">
import AddressPicker from '../../components/AddressPicker.vue';
import { emptyAddress, type AddressParts } from '@/shared/lib/geo';
import { computed, onMounted, reactive, ref } from 'vue';
import { Info, Package, Pencil, Plus, Star, TriangleAlert, Wallet, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import SettingsShell from '../settings/SettingsShell.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
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
const used = ref(0);
const atLimit = computed(() => limit.value !== null && used.value >= limit.value);
const totals = computed(() => {
    const sale = warehouses.value.filter((w) => w.purpose !== 'defective');
    const sum = (list: Warehouse[], k: 'on_hand' | 'sku_count') => list.reduce((a, w) => a + (w[k] ?? 0), 0);
    const values = warehouses.value.map((w) => w.stock_value);
    return {
        onHand: sum(sale, 'on_hand'),
        skus: sum(sale, 'sku_count'),
        value: values.some((v) => v === null || v === undefined) ? null : values.reduce<number>((a, v) => a + (v ?? 0), 0),
        defective: sum(warehouses.value.filter((w) => w.purpose === 'defective'), 'on_hand'),
    };
});

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/warehouses');
        warehouses.value = data.data;
        limit.value = data.meta.limit;
        used.value = data.meta.used ?? data.data.length;
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
// Pickup address for carriers: province → ward → street (an old free-text address stays until one is picked).
const addr = ref<AddressParts>(emptyAddress());
const addrLegacy = ref<string | null>(null);
const saving = ref(false);
const error = ref<ApiError | null>(null);

function openCreate() {
    editing.value = null;
    Object.assign(form, { code: '', name: '', address: '' });
    addr.value = emptyAddress();
    addrLegacy.value = null;
    error.value = null;
    open.value = true;
}
function openEdit(w: Warehouse) {
    editing.value = w;
    Object.assign(form, { code: w.code, name: w.name, address: w.address ?? '' });
    addr.value = w.province_code ? { street: w.street ?? '', province_code: w.province_code, ward_code: w.ward_code ?? '' } : emptyAddress();
    addrLegacy.value = w.province_code ? null : w.address;
    error.value = null;
    open.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const address = addr.value.province_code ? { street: addr.value.street || null, province_code: addr.value.province_code, ward_code: addr.value.ward_code || null } : { address: form.address || null };
        if (editing.value) await api.patch(`/warehouses/${editing.value.id}`, { name: form.name, ...address });
        else await api.post('/warehouses', { code: form.code, name: form.name, ...address });
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
    <SettingsShell title="Kho, chi nhánh" description="Mỗi SKU có tồn riêng theo từng kho. Mã kho in trên chứng từ, không đổi được sau khi tạo.">
        <template #actions>
            <button
                v-if="canManage"
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                :disabled="atLimit"
                data-testid="new-warehouse"
                @click="openCreate"
            >
                <Plus class="size-4" /> Thêm kho
            </button>
        </template>

        <div v-if="atLimit && canManage" class="mb-4 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-[14px] text-amber-800">
            Gói hiện tại cho phép tối đa <b>{{ limit }}</b> kho bán hàng. <RouterLink to="/subscription" class="font-medium text-blue-700 hover:underline">Xem gói dịch vụ</RouterLink> để thêm kho.
        </div>

        <div v-if="loading" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-52 rounded-[14px]" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <template v-else>
            <div class="mb-5 grid gap-4 sm:grid-cols-2 2xl:grid-cols-4" data-testid="warehouse-kpis">
                <ReportKpi label="Số kho bán hàng" :icon="WarehouseIcon" tone="blue" plain :value="null" :text="limit !== null ? `${used}/${limit}` : String(used)">
                    {{ limit !== null ? `Gói hiện tại cho tối đa ${limit} kho` : 'Không giới hạn số kho' }}
                </ReportKpi>
                <ReportKpi label="Tổng tồn" :icon="Package" tone="green" plain :value="totals.onHand" unit="">{{ formatNumber(totals.skus) }} SKU có tồn</ReportKpi>
                <ReportKpi v-if="totals.value !== null" label="Giá trị tồn" :icon="Wallet" tone="violet" plain :value="totals.value ?? 0">Theo giá vốn</ReportKpi>
                <ReportKpi label="Hàng lỗi chờ xử lý" :icon="TriangleAlert" tone="amber" plain :value="totals.defective" unit="">Trong kho hàng lỗi</ReportKpi>
            </div>

            <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" data-testid="warehouse-list">
                <div
                    v-for="w in warehouses"
                    :key="w.id"
                    :class="cn('flex flex-col rounded-[14px] border bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]', w.is_default ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200/80', w.status !== 'active' && 'opacity-70')"
                >
                    <div class="flex items-start gap-3.5">
                        <span :class="cn('grid size-11 shrink-0 place-items-center rounded-[12px]', w.purpose === 'defective' ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600')"><WarehouseIcon class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[16px] font-semibold text-slate-900">{{ w.name }}</p>
                                <span v-if="w.is_default" class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-[12px] font-medium text-blue-700"><Star class="size-3" /> Mặc định</span>
                                <span v-if="w.purpose === 'defective'" class="rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700" data-testid="warehouse-defective">Hàng lỗi · không bán</span>
                                <span v-if="w.status !== 'active'" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-600">Ngừng hoạt động</span>
                            </div>
                            <p class="mt-0.5 truncate text-[13px] text-slate-500"><span class="font-mono">{{ w.code }}</span> · {{ w.address || 'Chưa có địa chỉ' }}</p>
                        </div>
                    </div>
                    <div :class="cn('mt-5 grid gap-3 rounded-[12px] bg-slate-50 p-4', w.stock_value != null ? 'grid-cols-3' : 'grid-cols-2')">
                        <div><p class="text-[12px] text-slate-500">SKU có tồn</p><p class="mt-0.5 text-[18px] font-bold tabular-nums text-slate-900">{{ formatNumber(w.sku_count ?? 0) }}</p></div>
                        <div><p class="text-[12px] text-slate-500">Tổng tồn</p><p class="mt-0.5 text-[18px] font-bold tabular-nums text-slate-900">{{ formatNumber(w.on_hand ?? 0) }}</p></div>
                        <div v-if="w.stock_value != null"><p class="text-[12px] text-slate-500">Giá trị tồn</p><p class="mt-0.5 whitespace-nowrap text-[18px] font-bold tabular-nums text-slate-900">{{ formatMoney(w.stock_value) }}</p></div>
                    </div>
                    <div v-if="canManage" class="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
                        <button
                            v-if="!w.is_default"
                            type="button"
                            class="rounded-[9px] px-2 py-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                            @click="update(w, { status: w.status === 'active' ? 'inactive' : 'active' }, 'Đã đổi trạng thái kho')"
                        >
                            {{ w.status === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt lại' }}
                        </button>
                        <span class="ml-auto flex gap-2">
                            <button
                                v-if="!w.is_default && w.status === 'active' && w.purpose !== 'defective'"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-[9px] border border-slate-200 px-3 py-1.5 text-slate-600 hover:border-blue-300 hover:text-blue-700"
                                @click="update(w, { is_default: true }, `${w.code} là kho mặc định`)"
                            >
                                <Star class="size-3.5" /> Đặt mặc định
                            </button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-[9px] border border-slate-200 px-3 py-1.5 text-slate-600 hover:border-blue-300 hover:text-blue-700" @click="openEdit(w)">
                                <Pencil class="size-3.5" /> Sửa
                            </button>
                        </span>
                    </div>
                </div>
            </div>
            <p v-if="warehouses.some((w) => w.purpose === 'defective')" class="mt-4 flex items-center gap-2.5 rounded-[14px] border border-dashed border-slate-300 bg-white/60 px-5 py-4 text-[14px] text-slate-500">
                <Info class="size-4 shrink-0" /> Kho hàng lỗi không hiện khi tạo đơn bán và không tính vào giới hạn kho của gói. Hàng hoàn bị lỗi nhận vào kho này.
            </p>
        </template>
    </SettingsShell>

    <Dialog v-model:open="open" :title="editing ? `Sửa kho ${editing.code}` : 'Thêm kho'" description="Mã kho dùng trên chứng từ và không đổi được sau khi tạo.">
        <form id="warehouse-form" class="space-y-4" @submit.prevent="save">
            <FormField label="Mã kho" for="wh-code" :error="error?.field('code')" hint="Chữ, số, - và _ (ví dụ HCM, HN-01)">
                <Input id="wh-code" v-model="form.code" :disabled="!!editing" required maxlength="32" class="uppercase" />
            </FormField>
            <FormField label="Tên kho" for="wh-name" :error="error?.field('name')"><Input id="wh-name" v-model="form.name" required maxlength="255" /></FormField>
            <AddressPicker
                v-model="addr"
                id-prefix="wh-addr"
                :quick="false"
                :legacy="addrLegacy"
                :columns="1"
                :errors="{ street: error?.field('street') ?? error?.field('address'), province_code: error?.field('province_code'), ward_code: error?.field('ward_code') }"
            />
            <p v-if="error && !error.body.errors" class="text-sm text-destructive">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="warehouse-form" :loading="saving">{{ editing ? 'Lưu' : 'Tạo kho' }}</Button>
        </template>
    </Dialog>
</template>
