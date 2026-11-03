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
import { purchaseCosts, VAT_RATES } from './costs';
import type { PurchaseOrderData, Supplier, Variant, Warehouse } from '@/shared/types';

interface Line {
    id: string;
    sku: string;
    name: string;
    stock: number | null;
    quantity: string;
    unit_cost: string;
    tax_rate: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const editId = computed(() => (route.params.id ? String(route.params.id) : null));

const suppliers = ref<Supplier[]>([]);
const warehouses = ref<Warehouse[]>([]);
const form = reactive({ supplier_id: '', warehouse_id: '', expected_at: '', note: '', extra_cost: '', extra_cost_note: '', extra_cost_to_supplier: false, vat_in_cost: false });
const defaultVat = ref('0');
const lines = ref<Line[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

const costs = computed(() => purchaseCosts(lines.value, form));
const vatOptions = VAT_RATES.map((r) => ({ value: String(r), label: `${r}%` }));

onMounted(async () => {
    const [s, w] = await Promise.all([api.get('/suppliers', { params: { active: 1, per_page: 200 } }), api.get('/warehouses')]);
    suppliers.value = s.data.data;
    warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active');
    form.warehouse_id = (warehouses.value.find((x) => x.is_default) ?? warehouses.value[0])?.id ?? '';
    if (route.query.supplier) form.supplier_id = String(route.query.supplier);

    if (editId.value) {
        const po: PurchaseOrderData = (await api.get(`/purchase-orders/${editId.value}`)).data.data;
        Object.assign(form, {
            supplier_id: po.supplier?.id ?? '',
            warehouse_id: po.warehouse?.id ?? '',
            expected_at: po.expected_at ?? '',
            note: po.note ?? '',
            extra_cost: Number(po.extra_cost) ? String(Math.round(Number(po.extra_cost))) : '',
            extra_cost_note: po.extra_cost_note ?? '',
            extra_cost_to_supplier: po.extra_cost_to_supplier,
            vat_in_cost: po.vat_in_cost,
        });
        lines.value = (po.lines ?? []).map((l) => ({ id: l.variant!.id, sku: l.variant!.sku, name: l.variant!.name, stock: null, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))), tax_rate: String(Number(l.tax_rate)) }));
    } else if (route.query.from === 'reorder') {
        try {
            const draft: ReorderDraft = JSON.parse(sessionStorage.getItem(REORDER_DRAFT_KEY) ?? 'null');
            if (draft) {
                form.warehouse_id = draft.warehouse_id || form.warehouse_id;
                form.supplier_id = draft.supplier_id || form.supplier_id;
                lines.value = draft.lines.map((l) => ({ ...l, quantity: String(l.quantity), unit_cost: String(Math.round(Number(l.unit_cost))), tax_rate: defaultVat.value }));
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
        tax_rate: defaultVat.value,
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
        extra_cost: Number(form.extra_cost) || 0,
        extra_cost_note: form.extra_cost_note || null,
        extra_cost_to_supplier: form.extra_cost_to_supplier,
        vat_in_cost: form.vat_in_cost,
        lines: lines.value.map((l) => ({ variant_id: l.id, quantity: Number(l.quantity), unit_cost: Number(l.unit_cost || 0), tax_rate: Number(l.tax_rate) || 0 })),
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
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">SKU</th>
                            <th class="px-4 py-2 text-right font-medium">Tồn</th>
                            <th class="px-4 py-2 text-right font-medium">Số lượng</th>
                            <th class="px-4 py-2 text-right font-medium">Đơn giá nhập</th>
                            <th class="px-4 py-2 text-right font-medium">VAT</th>
                            <th class="px-4 py-2 text-right font-medium">Thành tiền</th>
                            <th class="px-4 py-2 text-right font-medium" title="Giá vốn mỗi đơn vị khi nhập kho: đơn giá + phần chi phí nhập (+ VAT nếu tính vào giá vốn)">Giá vốn</th>
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
                            <td class="px-4 py-2.5 text-right">
                                <select v-model="l.tax_rate" class="h-8 rounded-md border border-input bg-card px-1 text-sm" :data-testid="`po-vat-${i}`" aria-label="Thuế GTGT">
                                    <option v-for="o in vatOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                                <p v-if="lineError(i, 'tax_rate')" class="text-xs text-destructive">{{ lineError(i, 'tax_rate') }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney((Number(l.quantity) || 0) * (Number(l.unit_cost) || 0)) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-muted-foreground" :data-testid="`po-landed-${i}`">{{ formatNumber(costs.landed[i]) }}</td>
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
            <Card title="Thuế và chi phí nhập" description="Chi phí nhập (vận chuyển, bốc xếp) được phân bổ vào giá vốn theo giá trị hàng.">
                <div class="space-y-3 p-5 text-sm">
                    <FormField label="VAT mặc định cho dòng mới" for="po-default-vat">
                        <Select id="po-default-vat" v-model="defaultVat" :options="vatOptions" />
                    </FormField>
                    <label class="flex items-start gap-2"><input v-model="form.vat_in_cost" type="checkbox" class="mt-0.5" data-testid="po-vat-in-cost" /> <span>Tính VAT vào giá vốn <span class="block text-xs text-muted-foreground">Chọn khi không được khấu trừ thuế đầu vào (hộ kinh doanh, phương pháp trực tiếp).</span></span></label>
                    <FormField label="Chi phí nhập hàng" for="po-extra" :error="error?.field('extra_cost')">
                        <Input id="po-extra" v-model="form.extra_cost" type="number" min="0" class="text-right tabular-nums" data-testid="po-extra-cost" />
                    </FormField>
                    <FormField label="Diễn giải chi phí" for="po-extra-note"><Input id="po-extra-note" v-model="form.extra_cost_note" placeholder="Phí vận chuyển, bốc xếp…" /></FormField>
                    <label class="flex items-start gap-2"><input v-model="form.extra_cost_to_supplier" type="checkbox" class="mt-0.5" data-testid="po-extra-to-supplier" /> <span>Chi phí trả cho nhà cung cấp <span class="block text-xs text-muted-foreground">Có trên hóa đơn NCC, cộng vào công nợ. Bỏ chọn nếu trả cho đơn vị vận chuyển.</span></span></label>
                </div>
            </Card>
            <Card>
                <div class="space-y-2 p-5 text-sm">
                    <div class="flex justify-between"><span class="text-muted-foreground">Số lượng</span><span class="tabular-nums">{{ formatNumber(costs.quantity) }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Tiền hàng</span><span class="tabular-nums">{{ formatMoney(costs.subtotal) }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Thuế GTGT</span><span class="tabular-nums" data-testid="po-tax">{{ formatMoney(costs.tax) }}</span></div>
                    <div v-if="Number(form.extra_cost)" class="flex justify-between"><span class="text-muted-foreground">Chi phí nhập {{ form.extra_cost_to_supplier ? '(trả NCC)' : '(trả bên khác)' }}</span><span class="tabular-nums">{{ formatMoney(form.extra_cost) }}</span></div>
                    <div class="flex justify-between text-base font-semibold"><span>Phải trả NCC</span><span class="tabular-nums" data-testid="po-total">{{ formatMoney(costs.total) }}</span></div>
                    <div class="flex flex-col gap-2 pt-3">
                        <Button type="submit" :loading="saving" :disabled="!lines.length || !form.supplier_id" data-testid="po-place">{{ editId ? 'Lưu thay đổi' : 'Đặt hàng' }}</Button>
                        <Button v-if="!editId" type="button" variant="outline" :disabled="!lines.length || !form.supplier_id || saving" data-testid="po-draft" @click="submit(false)">Lưu nháp</Button>
                    </div>
                </div>
            </Card>
        </div>
    </form>
</template>
