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
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { adjustmentReasons, documentKinds, type DocumentKindKey } from './labels';
import type { Variant, Warehouse } from '@/shared/types';

interface Line {
    variant: Variant;
    quantity: string;
    unit_cost: string;
    mode: 'delta' | 'count';
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const kindKey = computed(() => route.meta.kind as DocumentKindKey);
const kind = computed(() => documentKinds[kindKey.value]);

const warehouses = ref<Warehouse[]>([]);
const form = reactive({ warehouse_id: '', to_warehouse_id: '', reason: 'stocktake', note: '' });
const lines = ref<Line[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

const activeWarehouses = computed(() => warehouses.value.filter((w) => w.status === 'active').map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })));
const sourceWarehouse = computed(() => form.warehouse_id);
const total = computed(() => lines.value.reduce((t, l) => ({ qty: t.qty + (Number(l.quantity) || 0), value: t.value + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0) }), { qty: 0, value: 0 }));

onMounted(async () => {
    warehouses.value = (await api.get('/warehouses')).data.data;
    const def = warehouses.value.find((w) => w.is_default && w.status === 'active') ?? warehouses.value[0];
    form.warehouse_id = def?.id ?? '';
    form.to_warehouse_id = warehouses.value.find((w) => w.id !== form.warehouse_id && w.status === 'active')?.id ?? '';
});

function add(variant: Variant) {
    const existing = lines.value.find((l) => l.variant.id === variant.id);
    if (existing) {
        existing.quantity = String((Number(existing.quantity) || 0) + 1);
        return;
    }
    lines.value.push({
        variant,
        quantity: kindKey.value === 'adjustments' ? '' : '1',
        unit_cost: variant.cost_price && Number(variant.cost_price) > 0 ? String(Math.round(Number(variant.cost_price))) : '',
        mode: 'count',
    });
}

function lineError(i: number, field: string): string | undefined {
    return error.value?.field(`lines.${i}.${field}`);
}

function countDelta(l: Line): number | null {
    if (kindKey.value !== 'adjustments' || l.mode !== 'count' || l.quantity === '' || !l.variant.stock) return null;
    return Number(l.quantity) - l.variant.stock.on_hand;
}

async function submit() {
    saving.value = true;
    error.value = null;
    try {
        const payloadLines = lines.value.map((l) => ({
            variant_id: l.variant.id,
            quantity: Number(l.quantity),
            ...(kindKey.value === 'receipts' ? { unit_cost: Number(l.unit_cost || 0) } : {}),
            ...(kindKey.value === 'adjustments' ? { mode: l.mode } : {}),
        }));
        const body =
            kindKey.value === 'transfers'
                ? { from_warehouse_id: form.warehouse_id, to_warehouse_id: form.to_warehouse_id, note: form.note || null, lines: payloadLines }
                : { warehouse_id: form.warehouse_id, note: form.note || null, lines: payloadLines, ...(kindKey.value === 'adjustments' ? { reason: form.reason } : {}) };
        const { data } = await api.post(`/inventory/${kindKey.value}`, body);
        toast.success(`Đã tạo ${data.data.number}`);
        await router.push(`/inventory/${kindKey.value}/${data.data.id}`);
    } catch (e) {
        error.value = e as ApiError;
        if (!error.value.body.errors) toast.error(error.value.message);
    } finally {
        saving.value = false;
    }
}

const hints: Record<DocumentKindKey, string> = {
    receipts: 'Tồn và giá vốn bình quân được cập nhật ngay khi lưu phiếu.',
    adjustments: 'Phiếu ở trạng thái chờ duyệt; tồn chỉ thay đổi khi người có quyền duyệt phê duyệt.',
    transfers: 'Phiếu được lưu nháp. Bấm "Xuất kho" để trừ kho nguồn, "Nhận hàng" để cộng kho đích.',
};
</script>

<template>
    <RouterLink :to="`/inventory/${kindKey}`" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> {{ kind.title }}</RouterLink>
    <PageHeader :title="kind.create" :description="hints[kindKey]" />

    <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit">
        <Card class="lg:col-span-2" title="Hàng hóa" :description="kindKey === 'adjustments' ? 'Kiểm kê: nhập số đếm thực tế. Chênh lệch: nhập số tăng (+) hoặc giảm (−).' : 'Quét mã vạch hoặc tìm SKU để thêm dòng.'">
            <div class="border-b p-4">
                <SkuPicker :warehouse="sourceWarehouse" :exclude="[]" @pick="add" />
                <p v-if="error?.field('lines')" class="mt-2 text-xs text-destructive">{{ error.field('lines') }}</p>
            </div>
            <EmptyState v-if="!lines.length" :icon="PackageOpen" title="Chưa có dòng hàng" description="Tìm SKU ở ô phía trên. Máy quét mã vạch hoạt động như bàn phím: quét xong nhấn Enter là thêm dòng." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="line-table">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">SKU</th>
                            <th class="px-4 py-2 text-right font-medium">Tồn hiện tại</th>
                            <th v-if="kindKey === 'adjustments'" class="px-4 py-2 font-medium">Cách nhập</th>
                            <th class="px-4 py-2 text-right font-medium">{{ kindKey === 'adjustments' ? 'Số lượng' : 'Số lượng' }}</th>
                            <th v-if="kindKey === 'receipts'" class="px-4 py-2 text-right font-medium">Đơn giá nhập</th>
                            <th v-if="kindKey === 'receipts'" class="px-4 py-2 text-right font-medium">Thành tiền</th>
                            <th v-if="kindKey === 'adjustments'" class="px-4 py-2 text-right font-medium">Chênh lệch</th>
                            <th class="px-2 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in lines" :key="l.variant.id" class="align-top">
                            <td class="px-4 py-2.5">
                                <p class="font-mono text-xs font-medium">{{ l.variant.sku }}</p>
                                <p class="max-w-56 truncate text-xs text-muted-foreground">{{ l.variant.product?.name }}<template v-if="l.variant.name"> · {{ l.variant.name }}</template></p>
                                <p v-if="lineError(i, 'variant_id')" class="text-xs text-destructive">{{ lineError(i, 'variant_id') }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">
                                {{ l.variant.stock ? formatNumber(l.variant.stock.on_hand) : '—' }}
                                <p v-if="l.variant.stock?.reserved" class="text-xs">khả dụng {{ formatNumber(l.variant.stock.available) }}</p>
                            </td>
                            <td v-if="kindKey === 'adjustments'" class="px-4 py-2.5">
                                <select v-model="l.mode" class="h-8 rounded-md border border-input bg-card px-2 text-sm">
                                    <option value="count">Số đếm thực tế</option>
                                    <option value="delta">Chênh lệch ±</option>
                                </select>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <input
                                    v-model="l.quantity"
                                    type="number"
                                    :min="kindKey === 'adjustments' && l.mode === 'delta' ? undefined : kindKey === 'adjustments' ? 0 : 1"
                                    required
                                    :data-testid="`qty-${i}`"
                                    :class="cn('h-8 w-24 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums', lineError(i, 'quantity') && 'border-destructive')"
                                />
                                <p v-if="lineError(i, 'quantity')" class="text-xs text-destructive">{{ lineError(i, 'quantity') }}</p>
                            </td>
                            <td v-if="kindKey === 'receipts'" class="px-4 py-2.5 text-right">
                                <input v-model="l.unit_cost" type="number" min="0" step="any" required :data-testid="`cost-${i}`" class="h-8 w-32 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums" />
                            </td>
                            <td v-if="kindKey === 'receipts'" class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney((Number(l.quantity) || 0) * (Number(l.unit_cost) || 0)) }}</td>
                            <td v-if="kindKey === 'adjustments'" class="px-4 py-2.5 text-right tabular-nums">
                                <template v-if="l.mode === 'delta'">{{ l.quantity ? (Number(l.quantity) > 0 ? '+' : '') + l.quantity : '—' }}</template>
                                <template v-else-if="countDelta(l) !== null">
                                    <span :class="cn(countDelta(l)! > 0 && 'text-success', countDelta(l)! < 0 && 'text-destructive')">{{ countDelta(l)! > 0 ? '+' : '' }}{{ countDelta(l) }}</span>
                                    <p class="text-[11px] text-muted-foreground">tính lại khi duyệt</p>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-2 py-2.5 text-right">
                                <Button variant="ghost" size="icon" class="size-8" title="Xóa dòng" @click="lines.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div class="space-y-5">
            <Card title="Thông tin phiếu" class="lg:sticky lg:top-4">
                <div class="space-y-4 p-5">
                    <FormField :label="kindKey === 'transfers' ? 'Kho xuất' : 'Kho'" for="doc-warehouse" :error="error?.field('warehouse_id') ?? error?.field('from_warehouse_id')">
                        <Select id="doc-warehouse" v-model="form.warehouse_id" :options="activeWarehouses" data-testid="doc-warehouse" />
                    </FormField>
                    <FormField v-if="kindKey === 'transfers'" label="Kho nhận" for="doc-to" :error="error?.field('to_warehouse_id')">
                        <Select id="doc-to" v-model="form.to_warehouse_id" :options="activeWarehouses.filter((w) => w.value !== form.warehouse_id)" data-testid="doc-to-warehouse" />
                    </FormField>
                    <FormField v-if="kindKey === 'adjustments'" label="Lý do" for="doc-reason">
                        <Select id="doc-reason" v-model="form.reason" :options="Object.entries(adjustmentReasons).map(([value, label]) => ({ value, label }))" />
                    </FormField>
                    <FormField label="Ghi chú" for="doc-note">
                        <textarea id="doc-note" v-model="form.note" rows="3" maxlength="1000" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
                    </FormField>

                    <div class="space-y-1 rounded-md bg-muted/50 p-3 text-sm">
                        <div class="flex justify-between"><span class="text-muted-foreground">Số dòng</span><span class="tabular-nums">{{ lines.length }}</span></div>
                        <div v-if="kindKey !== 'adjustments'" class="flex justify-between"><span class="text-muted-foreground">Tổng số lượng</span><span class="tabular-nums">{{ formatNumber(total.qty) }}</span></div>
                        <div v-if="kindKey === 'receipts'" class="flex justify-between font-medium"><span>Tổng giá trị</span><span class="tabular-nums">{{ formatMoney(total.value) }}</span></div>
                    </div>
                    <p v-if="error && !error.body.errors" class="text-sm text-destructive">{{ error.message }}</p>
                    <Button type="submit" class="w-full" :loading="saving" :disabled="!lines.length" data-testid="submit-document">
                        {{ kindKey === 'receipts' ? 'Lưu và nhập kho' : kindKey === 'adjustments' ? 'Gửi duyệt' : 'Lưu phiếu nháp' }}
                    </Button>
                </div>
            </Card>
        </div>
    </form>
</template>
