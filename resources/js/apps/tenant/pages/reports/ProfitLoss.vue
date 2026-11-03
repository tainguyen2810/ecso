<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ChevronRight, Download, Info } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

interface Line {
    key: string;
    label: string;
    level: number;
    report: string | null;
    current: number;
    previous: number;
    change: number | null;
}
interface PlData {
    range: { from: string; to: string };
    previous_range: { from: string; to: string };
    lines: Line[];
    missing_cost_lines: number;
}

/** Lines whose increase is bad news: the change badge is red when they go up. */
const COSTS = ['discount', 'returns', 'cogs', 'cogs_out', 'stock_loss', 'write_off', 'stocktake', 'other_expenses'];
const DRILL: Record<string, { name: string; params?: Record<string, string>; query?: Record<string, string> }> = {
    sales: { name: 'report-sales' },
    products: { name: 'report-detail', params: { report: 'product-sales' } },
    returns: { name: 'report-detail', params: { report: 'returns' } },
    stock: { name: 'report-stock' },
    cash: { name: 'cash' },
};

const { range } = useReportQuery();
const data = ref<PlData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/profit-loss', { params: range.value })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to], load);

const dmy = (iso: string) => iso.split('-').reverse().join('/');
function changeTone(l: Line): string {
    if (l.change === null || l.change === 0) return 'text-muted-foreground';
    const good = COSTS.includes(l.key) ? l.change < 0 : l.change > 0;
    return good ? 'text-success' : 'text-destructive';
}
function drill(l: Line) {
    const d = l.report ? DRILL[l.report] : undefined;
    return d ? { ...d, query: { ...range.value, ...d.query } } : null;
}
function exportCsv() {
    if (!data.value) return;
    const d = data.value;
    downloadCsv(
        `lai-lo-${d.range.from}-${d.range.to}.csv`,
        toCsv(
            ['Chỉ tiêu', `Kỳ trước (${dmy(d.previous_range.from)} - ${dmy(d.previous_range.to)})`, `Kỳ này (${dmy(d.range.from)} - ${dmy(d.range.to)})`, '% thay đổi'],
            d.lines.map((l) => [l.label, l.previous, l.current, l.change]),
        ),
    );
}
</script>

<template>
    <PageHeader title="Báo cáo lãi lỗ" description="Doanh thu, giá vốn và lợi nhuận của kỳ đang xem, đặt cạnh kỳ trước có cùng số ngày.">
        <RangePicker :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
    </PageHeader>
    <ReportsNav />

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="space-y-2"><Skeleton v-for="i in 8" :key="i" class="h-8" /></div>
    <template v-else>
        <p class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>
                Tính trên đơn đặt trong kỳ (trừ đơn hủy). Phí sàn và các khoản thu, chi khác chưa có trong hệ thống nên chưa trừ.
                <template v-if="data.missing_cost_lines"> {{ formatNumber(data.missing_cost_lines) }} dòng hàng đã xuất chưa có giá vốn, lợi nhuận đang cao hơn thực tế.</template>
                Bấm vào dòng có mũi tên để xem chi tiết.
            </span>
        </p>
        <Card>
            <div class="flex items-center justify-end border-b p-3">
                <Button variant="outline" size="sm" @click="exportCsv"><Download /> Xuất Excel (CSV)</Button>
            </div>
            <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full text-sm" data-testid="pl-table">
                    <thead class="border-b bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium">Chỉ tiêu</th>
                            <th class="px-4 py-2.5 text-right font-medium">Kỳ trước<span class="block font-normal normal-case">{{ dmy(data.previous_range.from) }} – {{ dmy(data.previous_range.to) }}</span></th>
                            <th class="px-4 py-2.5 text-right font-medium">Kỳ này<span class="block font-normal normal-case">{{ dmy(data.range.from) }} – {{ dmy(data.range.to) }}</span></th>
                            <th class="px-4 py-2.5 text-right font-medium">Thay đổi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in data.lines" :key="l.key" :class="cn(l.level === 0 && 'bg-muted/20 font-semibold', l.key === 'profit' && 'bg-primary/5 text-base')" :data-testid="`pl-${l.key}`">
                            <td :class="cn('px-4 py-2.5', l.level === 1 && 'pl-9')">
                                <RouterLink v-if="drill(l)" :to="drill(l)!" class="inline-flex items-center gap-1 hover:text-primary hover:underline">{{ l.label }} <ChevronRight class="size-3.5 text-muted-foreground" /></RouterLink>
                                <template v-else>{{ l.label }}</template>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatMoney(l.previous) }}</td>
                            <td :class="cn('whitespace-nowrap px-4 py-2.5 text-right tabular-nums', l.current < 0 && 'text-destructive')">{{ formatMoney(l.current) }}</td>
                            <td :class="cn('whitespace-nowrap px-4 py-2.5 text-right tabular-nums', changeTone(l))">{{ l.change === null ? '—' : `${l.change > 0 ? '+' : ''}${l.change.toLocaleString('vi-VN')}%` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>
</template>
