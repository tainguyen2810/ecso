<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, PackageOpen, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { REORDER_DRAFT_KEY, type ReorderDraft } from './reorder';
import type { PurchaseOrderData, Supplier, Variant, Warehouse } from '@/shared/types';

interface Line {
    id: string;
    sku: string;
    name: string;
    stock: number | null;
    quantity: string;
    unit_cost: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const editId = computed(() => (route.params.id ? String(route.params.id) : null));

const suppliers = ref<Supplier[]>([]);
const warehouses = ref<Warehouse[]>([]);
const form = reactive({ supplier_id: '', warehouse_id: '', expected_at: '', note: '' });
const lines = ref<Line[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

const total = computed(() => lines.value.reduce((t, l) => ({ qty: t.qty + (Number(l.quantity) || 0), value: t.value + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0) }), { qty: 0, value: 0 }));

onMounted(async () => {
    const [s, w] = await Promise.all([api.get('/suppliers', { params: { active: 1, per_page: 200 } }), api.get('/warehouses')]);
    suppliers.value = s.data.data;
    warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active');
    form.warehouse_id = (warehouses.value.find((x) => x.is_default) ?? warehouses.value[0])?.id ?? '';
    if (route.query.supplier) form.supplier_id = String(route.query.supplier);

    if (editId.value) {
        const po: PurchaseOrderData = (await api.get(`/purchase-orders/${editId.value}`)).data.data;
        Object.assign(form, { supplier_id: po.supplier?.id ?? '', warehouse_id: po.warehouse?.id ?? '', expected_at: po.expected_at ?? '', note: po.note ?? '' });
        lines.value = (po.lines ?? []).map((l) => ({ id: l.variant!.id, sku: l.variant!.sku, name: l.variant!.name, stock: null, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))) }));
    } else if (route.query.from === 'reorder') {
        try {
            const draft: ReorderDraft = JSON.parse(sessionStorage.getItem(REORDER_DRAFT_KEY) ?? 'null');
            if (draft) {
                form.warehouse_id = draft.warehouse_id || form.warehouse_id;
                form.supplier_id = draft.supplier_id || form.supplier_id;
                lines.value = draft.lines.map((l) => ({ ...l, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))) }));
            }
        } catch {
            /* storage unavailable: start empty */
        }
    }
});

function add(v: Variant) {
    const existing = lines.value.find((l) => l.id === v.id);
    if (existing) {
        existing.quantity = String((Number(existing.quantity) || 0) + 1);
        return;
    }
    lines.value.push({
        id: v.id!,
        sku: v.sku,
        name: `${v.product?.name ?? ''}${v.name ? ' · ' + v.name : ''}`,
        stock: v.stock?.on_hand ?? null,
        quantity: '1',
        unit_cost: v.cost_price && Number(v.cost_price) > 0 ? String(Math.round(Number(v.cost_price))) : '',
    });
}

function lineError(i: number, field: string): string | undefined {
    return error.value?.field(`lines.${i}.${field}`);
}

async function submit(place: boolean) {
    saving.value = true;
    error.value = null;
    const body = {
        supplier_id: form.supplier_id,
        warehouse_id: form.warehouse_id,
        expected_at: form.expected_at || null,
        note: form.note || null,
        lines: lines.value.map((l) => ({ variant_id: l.id, quantity: Number(l.quantity), unit_cost: Number(l.unit_cost || 0) })),
    };
    try {
        const { data } = editId.value ? await api.put(`/purchase-orders/${editId.value}`, body) : await api.post('/purchase-orders', { ...body, place });
        try {
            sessionStorage.removeItem(REORDER_DRAFT_KEY);
        } catch {
            /* ignore */
        }
        toast.success(editId.value ? 'Đã lưu đơn nhập.' : place ? `Đã đặt hàng ${data.data.number}.` : `Đã lưu nháp ${data.data.number}.`);
        await router.push(`/purchasing/${data.data.id}`);
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body.errors) toast.error(error.value.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RouterLink to="/purchasing" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Đơn nhập hàng</RouterLink>
    <PageHeader :title="editId ? 'Sửa đơn nhập' : 'Tạo đơn nhập hàng'" description="Đặt hàng chưa làm thay đổi tồn kho. Tồn và giá vốn chỉ cập nhật khi bấm Nhận hàng." />

    <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit(true)">
        <Card class="lg:col-span-2" title="Hàng đặt" description="Quét mã vạch hoặc tìm SKU để thêm dòng.">
            <div class="border-b p-4">
                <SkuPicker stocked :warehouse="form.warehouse_id" :exclude="[]" @pick="add" />
                <p v-if="error?.field('lines')" class="mt-2 text-xs text-destructive">{{ error.field('lines') }}</p>
            </div>
            <EmptyState v-if="!lines.length" :icon="PackageOpen" title="Chưa có dòng hàng" description="Tìm SKU ở ô phía trên, hoặc dùng tab Gợi ý nhập hàng để tạo đơn từ tốc độ bán." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="po-lines">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">SKU</th>
                            <th class="px-4 py-2 text-right font-medium">Tồn</th>
                            <th class="px-4 py-2 text-right font-medium">Số lượng</th>
                            <th class="px-4 py-2 text-right font-medium">Đơn giá nhập</th>
                            <th class="px-4 py-2 text-right font-medium">Thành tiền</th>
                            <th class="px-2 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in lines" :key="l.id" class="align-top">
                            <td class="px-4 py-2.5">
                                <p class="font-mono text-xs font-medium">{{ l.sku }}</p>
                                <p class="max-w-64 truncate text-xs text-muted-foreground">{{ l.name }}</p>
                                <p v-if="lineError(i, 'variant_id')" class="text-xs text-destructive">{{ lineError(i, 'variant_id') }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ l.stock === null ? '—' : formatNumber(l.stock) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <input v-model="l.quantity" type="number" min="1" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" :data-testid="`po-qty-${i}`" />
                                <p v-if="lineError(i, 'quantity')" class="text-xs text-destructive">{{ lineError(i, 'quantity') }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <input v-model="l.unit_cost" type="number" min="0" class="h-8 w-28 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" :data-testid="`po-cost-${i}`" />
                                <p v-if="lineError(i, 'unit_cost')" class="text-xs text-destructive">{{ lineError(i, 'unit_cost') }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney((Number(l.quantity) || 0) * (Number(l.unit_cost) || 0)) }}</td>
                            <td class="px-2 py-2.5"><button type="button" class="rounded p-1 text-muted-foreground hover:text-destructive" title="Xóa dòng" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div class="space-y-5">
            <Card title="Thông tin đơn">
                <div class="space-y-3 p-5">
                    <FormField label="Nhà cung cấp" :error="error?.field('supplier_id')">
                        <Select v-model="form.supplier_id" :options="[{ value: '', label: 'Chọn nhà cung cấp…' }, ...suppliers.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))]" data-testid="po-supplier" />
                    </FormField>
                    <p v-if="!suppliers.length" class="text-xs text-muted-foreground">Chưa có nhà cung cấp. <RouterLink to="/purchasing/suppliers" class="underline">Thêm ở tab Nhà cung cấp</RouterLink>.</p>
                    <FormField label="Nhập về kho" :error="error?.field('warehouse_id')">
                        <Select v-model="form.warehouse_id" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" />
                    </FormField>
                    <FormField label="Ngày dự kiến về" for="po-expected"><Input id="po-expected" v-model="form.expected_at" type="date" /></FormField>
                    <FormField label="Ghi chú" for="po-note"><Input id="po-note" v-model="form.note" /></FormField>
                </div>
            </Card>
            <Card>
                <div class="space-y-2 p-5 text-sm">
                    <div class="flex justify-between"><span class="text-muted-foreground">Số lượng</span><span class="tabular-nums">{{ formatNumber(total.qty) }}</span></div>
                    <div class="flex justify-between text-base font-semibold"><span>Tổng giá trị</span><span class="tabular-nums" data-testid="po-total">{{ formatMoney(total.value) }}</span></div>
                    <div class="flex flex-col gap-2 pt-3">
                        <Button type="submit" :loading="saving" :disabled="!lines.length || !form.supplier_id" data-testid="po-place">{{ editId ? 'Lưu thay đổi' : 'Đặt hàng' }}</Button>
                        <Button v-if="!editId" type="button" variant="outline" :disabled="!lines.length || !form.supplier_id || saving" data-testid="po-draft" @click="submit(false)">Lưu nháp</Button>
                    </div>
                </div>
            </Card>
        </div>
    </form>
</template>
