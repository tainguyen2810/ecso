<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Boxes, Download, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { downloadCsv, presetRange, toCsv } from './range';
import type { Warehouse } from '@/shared/types';

const COLUMNS = {
    purchase_in: 'Nhập mua',
    return_in: 'Khách hoàn',
    transfer_in: 'Chuyển đến',
    adjust_in: 'Đ/c tăng',
    sale_out: 'Xuất bán',
    supplier_out: 'Trả NCC',
    damage_out: 'Hủy / mất',
    adjust_out: 'Đ/c giảm',
    transfer_out: 'Chuyển đi',
} as const;
type Col = keyof typeof COLUMNS;
type Row = { variant: { id: string; sku: string; name: string }; opening: number; in: number; out: number; closing: number; unit_cost: number; closing_value: number } & Record<Col, number>;
interface LedgerData {
    rows: Row[];
    totals: Record<string, number>;
}

const range = ref(presetRange('month'));
const warehouses = ref<Warehouse[]>([]);
const warehouse = ref('');
const q = ref('');
const detail = ref(false);
const data = ref<LedgerData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/stock-ledger', { params: { ...range.value, warehouse: warehouse.value || undefined, q: q.value || undefined } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    warehouses.value = (await api.get('/warehouses')).data.data;
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch([range, warehouse], load, { deep: true });

const inCols: Col[] = ['purchase_in', 'return_in', 'transfer_in', 'adjust_in'];
const outCols: Col[] = ['sale_out', 'supplier_out', 'damage_out', 'adjust_out', 'transfer_out'];

function exportCsv() {
    if (!data.value) return;
    const cols = [...inCols, ...outCols];
    downloadCsv(
        `xuat-nhap-ton-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            ['SKU', 'Tên', 'Tồn đầu', ...cols.map((c) => COLUMNS[c]), 'Tổng nhập', 'Tổng xuất', 'Tồn cuối', 'Giá vốn', 'Giá trị tồn cuối'],
            data.value.rows.map((r) => [r.variant.sku, r.variant.name, r.opening, ...cols.map((c) => r[c]), r.in, r.out, r.closing, r.unit_cost, r.closing_value]),
        ),
    );
}
const n = (v: number) => (v ? formatNumber(v) : '—');
</script>

<template>
    <PageHeader title="Báo cáo" description="Xuất nhập tồn theo SKU trong kỳ, lấy trực tiếp từ sổ kho.">
        <RangePicker v-model:from="range.from" v-model:to="range.to" />
    </PageHeader>
    <ReportsNav />
    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <div class="relative min-w-56 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="SKU, tên sản phẩm…" />
            </div>
            <Select v-model="warehouse" class="w-44" :options="[{ value: '', label: 'Mọi kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" aria-label="Kho" />
            <label class="flex items-center gap-2 px-1 text-sm"><input v-model="detail" type="checkbox" class="size-4" data-testid="ledger-detail" /> Chi tiết loại nhập/xuất</label>
            <Button variant="outline" size="sm" @click="exportCsv"><Download /> Xuất CSV</Button>
        </div>
        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-9" /></div>
        <EmptyState v-else-if="!data.rows.length" :icon="Boxes" title="Không có biến động" description="Không có SKU nào có tồn hoặc phát sinh nhập xuất trong kỳ này." />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="ledger-table">
                <thead class="border-b bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr class="text-right">
                        <th class="px-4 py-2.5 text-left font-medium">SKU</th>
                        <th class="px-3 py-2.5 font-medium">Tồn đầu</th>
                        <template v-if="detail"><th v-for="c in inCols" :key="c" class="px-3 py-2.5 font-medium text-success">{{ COLUMNS[c] }}</th></template>
                        <th class="px-3 py-2.5 font-medium">Nhập</th>
                        <template v-if="detail"><th v-for="c in outCols" :key="c" class="px-3 py-2.5 font-medium text-destructive">{{ COLUMNS[c] }}</th></template>
                        <th class="px-3 py-2.5 font-medium">Xuất</th>
                        <th class="px-3 py-2.5 font-medium">Tồn cuối</th>
                        <th class="px-4 py-2.5 font-medium">Giá trị tồn</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in data.rows" :key="r.variant.id" class="text-right tabular-nums">
                        <td class="px-4 py-2 text-left"><RouterLink :to="`/inventory/sku/${r.variant.id}`" class="font-mono text-xs font-medium hover:underline">{{ r.variant.sku }}</RouterLink><p class="max-w-64 truncate text-xs text-muted-foreground">{{ r.variant.name }}</p></td>
                        <td class="px-3 py-2">{{ formatNumber(r.opening) }}</td>
                        <template v-if="detail"><td v-for="c in inCols" :key="c" class="px-3 py-2 text-muted-foreground">{{ n(r[c]) }}</td></template>
                        <td class="px-3 py-2 text-success">{{ r.in ? '+' + formatNumber(r.in) : '—' }}</td>
                        <template v-if="detail"><td v-for="c in outCols" :key="c" class="px-3 py-2 text-muted-foreground">{{ n(r[c]) }}</td></template>
                        <td class="px-3 py-2 text-destructive">{{ r.out ? '−' + formatNumber(r.out) : '—' }}</td>
                        <td class="px-3 py-2 font-medium" :class="r.closing < 0 && 'text-destructive'">{{ formatNumber(r.closing) }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ formatMoney(r.closing_value) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t bg-muted/30 font-medium">
                    <tr class="text-right tabular-nums">
                        <td class="px-4 py-2.5 text-left">Tổng {{ formatNumber(data.rows.length) }} SKU</td>
                        <td class="px-3 py-2.5">{{ formatNumber(data.totals.opening) }}</td>
                        <template v-if="detail"><td v-for="c in inCols" :key="c" class="px-3 py-2.5">{{ n(data.totals[c] ?? 0) }}</td></template>
                        <td class="px-3 py-2.5">{{ formatNumber(data.totals.in) }}</td>
                        <template v-if="detail"><td v-for="c in outCols" :key="c" class="px-3 py-2.5">{{ n(data.totals[c] ?? 0) }}</td></template>
                        <td class="px-3 py-2.5">{{ formatNumber(data.totals.out) }}</td>
                        <td class="px-3 py-2.5">{{ formatNumber(data.totals.closing) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ formatMoney(data.totals.closing_value) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p class="border-t px-4 py-2.5 text-xs text-muted-foreground">Giá trị tồn tính theo giá vốn bình quân hiện tại. "Hủy / mất" gồm phiếu điều chỉnh lý do Hàng hỏng và Thất lạc đã duyệt.</p>
        </div>
    </Card>
</template>
