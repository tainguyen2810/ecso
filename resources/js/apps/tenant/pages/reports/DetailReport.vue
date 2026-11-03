<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ChevronLeft, Download, FileBarChart, Info, PackagePlus, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import type { Warehouse } from '@/shared/types';
import { reports, type Column, type ColumnType } from './catalog';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

const route = useRoute();
const router = useRouter();
const report = computed(() => reports[route.params.report as string]!);
const { range, param, today } = useReportQuery();
const by = param('by');
const tab = computed(() => by.value || Object.keys(report.value.tabs ?? { '': '' })[0]!);
const date = param('date', today);
const warehouse = param('warehouse');
const q = ref(typeof route.query.q === 'string' ? route.query.q : '');

const warehouses = ref<Warehouse[]>([]);
const data = ref<any>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    const r = report.value;
    try {
        const params: Record<string, string | undefined> = {
            by: r.tabs ? tab.value : undefined,
            warehouse: r.warehouse ? warehouse.value || undefined : undefined,
            q: r.search ? q.value || undefined : undefined,
            ...(r.period === 'range' ? range.value : {}),
            ...(r.period === 'date' ? { date: date.value } : {}),
        };
        data.value = (await api.get(r.endpoint, { params })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    if (report.value.warehouse) warehouses.value = (await api.get('/warehouses')).data.data;
});
// A different report or tab has different columns: drop the old rows instead of showing them under new headers.
watch(() => [route.params.report, tab.value], () => (data.value = null));
watch(() => [route.params.report, tab.value, range.value.from, range.value.to, date.value, warehouse.value], load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

const columns = computed<Column[]>(() => (data.value ? report.value.columns(data.value, tab.value) : []));
const rows = computed<any[]>(() => (data.value ? (report.value.rows?.(data.value) ?? data.value.rows) : []));
const hasTotals = computed(() => columns.value.some((c) => c.total));

function raw(c: Column, r: any): string | number | null {
    return c.value ? c.value(r) : r[c.key];
}
function show(type: ColumnType | undefined, v: string | number | null): string {
    if (v === null || v === undefined || v === '') return '—';
    switch (type) {
        case 'money':
            return formatMoney(v);
        case 'number':
            return formatNumber(v);
        case 'pct':
            return `${Number(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;
        case 'date':
            return formatDate(String(v));
        case 'datetime':
            return formatDateTime(String(v));
        default:
            return String(v);
    }
}
function total(c: Column): string {
    if (!c.total) return '';
    const v = typeof c.total === 'function' ? c.total(data.value) : data.value.totals?.[c.key];
    return show(c.type, v);
}
const numeric = (c: Column) => ['money', 'number', 'pct'].includes(c.type ?? '');
const toneClass = { danger: 'text-destructive', success: 'text-success', muted: 'text-muted-foreground' } as const;

function exportCsv() {
    if (!data.value) return;
    const r = report.value;
    const suffix = r.period === 'range' ? `${range.value.from}-${range.value.to}` : r.period === 'date' ? date.value : today;
    downloadCsv(
        `${r.key}${r.tabs ? `-${tab.value}` : ''}-${suffix}.csv`,
        toCsv(
            columns.value.flatMap((c) => (c.sub ? [c.label, 'Mã'] : [c.label])),
            rows.value.map((row) => columns.value.flatMap((c) => (c.sub ? [raw(c, row), c.sub(row)] : [raw(c, row)]))),
        ),
    );
}
</script>

<template>
    <RouterLink :to="{ name: 'reports', query: report.period === 'range' ? range : {} }" class="-mt-2 mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground" data-testid="back-to-reports">
        <ChevronLeft class="size-4" /> Tất cả báo cáo
    </RouterLink>
    <PageHeader :title="report.title" :description="report.description">
        <RangePicker v-if="report.period === 'range'" :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
        <label v-else-if="report.period === 'date'" class="flex items-center gap-2 text-sm">
            <span class="text-muted-foreground">Tồn đến hết ngày</span>
            <Input v-model="date" type="date" :max="today" class="w-40" aria-label="Ngày xem tồn" data-testid="report-date" />
        </label>
    </PageHeader>

    <div v-if="data && report.kpis" class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="report-kpis">
        <KpiCard v-for="k in report.kpis(data)" :key="k.label" :label="k.label" :value="show(k.type, k.value)">
            <template v-if="k.hint">{{ k.hint }}</template>
        </KpiCard>
    </div>
    <p v-if="report.note" class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        <Info class="mt-0.5 size-3.5 shrink-0" /><span>{{ report.note }}</span>
    </p>

    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <div v-if="report.tabs" class="flex overflow-hidden rounded-md border text-sm" role="tablist">
                <button
                    v-for="(label, k) in report.tabs"
                    :key="k"
                    type="button"
                    role="tab"
                    :aria-selected="tab === k"
                    :class="cn('border-r px-3 py-1.5 last:border-r-0', tab === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')"
                    :data-testid="`tab-${k}`"
                    @click="by = String(k)"
                >
                    {{ label }}
                </button>
            </div>
            <div v-if="report.search" class="relative w-full sm:w-64">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" placeholder="Tìm SKU, tên sản phẩm" class="pl-8" aria-label="Tìm sản phẩm" />
            </div>
            <Select
                v-if="report.warehouse && warehouses.length > 1"
                v-model="warehouse"
                class="w-48"
                aria-label="Kho"
                :options="[{ value: '', label: 'Tất cả kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]"
            />
            <span v-if="data" class="text-xs text-muted-foreground">{{ formatNumber(rows.length) }} dòng</span>
            <Button v-if="report.action && rows.length" size="sm" class="ml-auto" @click="router.push(report.action.to)"><PackagePlus /> {{ report.action.label }}</Button>
            <Button variant="outline" size="sm" :class="report.action && rows.length ? undefined : 'ml-auto'" :disabled="!rows.length" data-testid="export-csv" @click="exportCsv"><Download /> Xuất Excel (CSV)</Button>
        </div>

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="space-y-2 p-5"><Skeleton v-for="i in 6" :key="i" class="h-6" /></div>
        <EmptyState v-else-if="!rows.length" :icon="FileBarChart" title="Chưa có số liệu" :description="report.empty" />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="report-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th v-for="c in columns" :key="c.key" :class="cn('whitespace-nowrap px-4 py-2.5 font-medium', numeric(c) && 'text-right')">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(row, i) in rows" :key="i" class="hover:bg-muted/30">
                        <td v-for="c in columns" :key="c.key" :class="cn('px-4 py-2', numeric(c) && 'whitespace-nowrap text-right tabular-nums', c.tone?.(row) && toneClass[c.tone(row)!])">
                            <RouterLink v-if="c.link?.(row)" :to="c.link(row)!" class="font-medium hover:text-primary hover:underline">{{ show(c.type, raw(c, row)) }}</RouterLink>
                            <template v-else>{{ show(c.type, raw(c, row)) }}</template>
                            <span v-if="c.sub?.(row)" class="block text-xs text-muted-foreground">{{ c.sub(row) }}</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="hasTotals" class="border-t bg-muted/30 font-medium">
                    <tr>
                        <td v-for="(c, i) in columns" :key="c.key" :class="cn('whitespace-nowrap px-4 py-2.5', numeric(c) && 'text-right tabular-nums')">{{ i === 0 ? 'Tổng' : total(c) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </Card>
</template>
