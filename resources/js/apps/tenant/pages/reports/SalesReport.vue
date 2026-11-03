<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Download, Info } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { channelTypes, marketplaceNames } from '../orders/labels';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

interface Row {
    label: string;
    kind: string | null;
    orders: number;
    units: number;
    revenue: number;
    goods: number;
    cogs: number;
    gross_profit: number;
    margin: number | null;
    missing_cost_lines: number;
}
interface SalesData {
    rows: Row[];
    totals: Row;
    excluded: { orders: number; revenue: number };
}

const { range, param } = useReportQuery();
type By = 'day' | 'channel' | 'staff' | 'customer' | 'warehouse';
const TABS: Record<By, string> = { day: 'Theo ngày', channel: 'Theo kênh', staff: 'Theo nhân viên', customer: 'Theo khách hàng', warehouse: 'Theo kho' };
const byParam = param('by', 'day');
const by = computed<By>({ get: () => (byParam.value in TABS ? (byParam.value as By) : 'day'), set: (v) => (byParam.value = v) });
const data = ref<SalesData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/sales', { params: { ...range.value, by: by.value } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to, by.value], load);

const maxRevenue = computed(() => Math.max(1, ...(data.value?.rows.map((r) => r.revenue) ?? [1])));
const firstColumn = computed(() => ({ day: 'Ngày', channel: 'Kênh bán', staff: 'Nhân viên', customer: 'Khách hàng', warehouse: 'Kho' })[by.value]);

function label(r: Row): string {
    if (by.value === 'day') {
        const [y, m, d] = r.label.split('-');
        return `${d}/${m}/${y}`;
    }
    return r.label;
}
function kindLabel(kind: string | null): string | null {
    if (!kind) return null;
    return marketplaceNames[kind] ?? channelTypes[kind] ?? kind;
}

function exportCsv() {
    if (!data.value) return;
    downloadCsv(
        `ban-hang-${by.value}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            [firstColumn.value, 'Số đơn', 'Số lượng', 'Doanh thu', 'Tiền hàng', 'Giá vốn', 'Lợi nhuận gộp', 'Biên LN %'],
            data.value.rows.map((r) => [label(r), r.orders, r.units, r.revenue, r.goods, r.cogs, r.gross_profit, r.margin]),
        ),
    );
}
</script>

<template>
    <PageHeader title="Bán hàng & lợi nhuận" description="Doanh thu, giá vốn và lợi nhuận gộp trên mọi kênh bán: sàn và kênh riêng.">
        <RangePicker :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
    </PageHeader>
    <ReportsNav />

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="sales-kpis">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Đơn hàng</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatNumber(data.totals.orders) }}</p><p class="text-xs text-muted-foreground">{{ formatNumber(data.totals.units) }} sản phẩm</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Doanh thu (gồm phí giao)</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(data.totals.revenue) }}</p><p class="text-xs text-muted-foreground">Tiền hàng {{ formatMoney(data.totals.goods) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Giá vốn</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(data.totals.cogs) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Lợi nhuận gộp</p><p class="mt-1 text-2xl font-semibold tabular-nums" :class="data.totals.gross_profit < 0 && 'text-destructive'">{{ formatMoney(data.totals.gross_profit) }}</p><p class="text-xs text-muted-foreground">Biên {{ data.totals.margin ?? '—' }}%</p></div></Card>
        </div>
        <p v-if="data.totals.missing_cost_lines || data.excluded.orders" class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>
                <template v-if="data.totals.missing_cost_lines">{{ formatNumber(data.totals.missing_cost_lines) }} dòng hàng chưa có giá vốn (đơn chưa xuất kho hoặc SKU sàn chưa ghép), lợi nhuận của các đơn này đang tính bằng tiền hàng. </template>
                <template v-if="data.excluded.orders">Không tính {{ formatNumber(data.excluded.orders) }} đơn hủy/hoàn ({{ formatMoney(data.excluded.revenue) }}).</template>
            </span>
        </p>

        <Card>
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <div class="flex overflow-hidden rounded-md border text-sm" role="tablist">
                    <button v-for="(l, k) in TABS" :key="k" type="button" :class="cn('border-r px-3 py-1.5 last:border-r-0', by === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')" :data-testid="`sales-by-${k}`" @click="by = k">{{ l }}</button>
                </div>
                <Button variant="outline" size="sm" class="ml-auto" @click="exportCsv"><Download /> Xuất CSV</Button>
            </div>
            <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full text-sm" data-testid="sales-table">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">{{ firstColumn }}</th>
                            <th class="px-4 py-2.5 text-right font-medium">Đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">SL</th>
                            <th class="w-1/4 px-4 py-2.5 font-medium">Doanh thu</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá vốn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Lợi nhuận gộp</th>
                            <th class="px-4 py-2.5 text-right font-medium">Biên</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in data.rows" :key="r.label + r.kind" :class="!r.orders && 'text-muted-foreground'">
                            <td class="whitespace-nowrap px-4 py-2">{{ label(r) }}<span v-if="kindLabel(r.kind)" class="ml-1.5 text-xs text-muted-foreground">{{ kindLabel(r.kind) }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.orders ? formatNumber(r.orders) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.units ? formatNumber(r.units) : '—' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: `${(r.revenue / maxRevenue) * 100}%` }" /></div>
                                    <span class="w-28 text-right tabular-nums">{{ r.revenue ? formatMoney(r.revenue) : '—' }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.cogs ? formatMoney(r.cogs) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums" :class="r.gross_profit < 0 && 'text-destructive'">{{ r.orders ? formatMoney(r.gross_profit) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.margin === null ? '—' : `${r.margin}%` }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-medium">
                        <tr>
                            <td class="px-4 py-2.5">Tổng</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(data.totals.orders) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(data.totals.units) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(data.totals.revenue) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(data.totals.cogs) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(data.totals.gross_profit) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ data.totals.margin === null ? '—' : `${data.totals.margin}%` }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </template>
</template>
