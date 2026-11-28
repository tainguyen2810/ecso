<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';
import { ArrowLeft, Download } from 'lucide-vue-next';
import DateInput from '@/shared/ui/DateInput.vue';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { groupOf, reportGroups, type ReportLink } from './groups';
import { presetRange, type Preset } from './range';

/**
 * Head of every report detail page (mẫu Tailwind 28/11): back to its report menu, big title, quick periods (or the
 * "as of" date), "Xuất báo cáo", then the other reports of the same menu as buttons to switch between them.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        period?: 'range' | 'date' | 'none';
        from?: string;
        to?: string;
        date?: string;
        max?: string;
        exporting?: boolean;
        canExport?: boolean;
        exportLabel?: string;
        exportTestid?: string;
    }>(),
    { period: 'range', description: undefined, from: '', to: '', date: '', max: undefined, exporting: false, canExport: true, exportLabel: 'Xuất báo cáo', exportTestid: 'export-csv' },
);
const emit = defineEmits<{ 'update:range': [range: { from: string; to: string }]; 'update:date': [date: string]; export: [] }>();

const route = useRoute();
const tenant = useTenantStore();
const group = computed(() => groupOf(String(route.name), route.params.report as string | undefined, String(route.query.by ?? '')));
const groupLabels = { sales: 'Bán hàng', purchases: 'Nhập hàng', stock: 'Kho', finance: 'Tài chính', customers: 'Khách hàng' } as const;

/** Same period for the next report when it takes one. */
function target(l: ReportLink): RouteLocationRaw {
    const query = l.ranged && props.from ? { from: props.from, to: props.to } : {};
    if ('report' in l.to) return { name: 'report-detail', params: { report: l.to.report }, query: { ...query, ...(l.to.by ? { by: l.to.by } : {}) } };
    return { name: l.to.name, query: { ...query, ...(l.to.query ?? {}) } };
}
function isCurrent(l: ReportLink): boolean {
    if ('report' in l.to) return route.name === 'report-detail' && route.params.report === l.to.report;
    if (l.to.name !== route.name) return false;
    const by = l.to.query?.by;
    return !by || by === (route.query.by ?? 'day');
}
/** Only the pages of the report section (Sổ kho opens Quản lý kho, not a report). */
const siblings = computed(() => {
    const links = reportGroups[group.value].links.filter((l) => tenant.can(l.permission) && ('report' in l.to || String(l.to.name).startsWith('report-'))).filter((l, i, all) => all.findIndex((x) => x.title === l.title) === i);
    // A solo report (Báo cáo lãi lỗ) stands alone: no tab row on it, and it is left out of its siblings' tab row.
    return links.some((l) => l.solo && isCurrent(l)) ? [] : links.filter((l) => !l.solo);
});

const quick: [Preset, string][] = [
    ['today', 'Hôm nay'],
    ['7d', '7 ngày'],
    ['month', 'Tháng này'],
    ['last-month', 'Tháng trước'],
];
const active = computed<Preset | 'custom'>(() => quick.find(([p]) => {
    const r = presetRange(p);
    return r.from === props.from && r.to === props.to;
})?.[0] ?? 'custom');
const picker = ref<HTMLElement | null>(null);
function custom() {
    picker.value?.querySelector<HTMLElement>('button, input')?.click();
}
</script>

<template>
    <div class="mb-5">
        <RouterLink
            :to="{ name: 'report-group', params: { group }, query: { from: route.query.from, to: route.query.to } }"
            class="inline-flex items-center gap-1.5 text-[14px] text-slate-500 hover:text-blue-700"
            data-testid="back-to-reports"
        >
            <ArrowLeft class="size-4" /> Báo cáo / {{ groupLabels[group] }}
        </RouterLink>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="report-title">{{ title }}</h1>
                <p v-if="description" class="mt-1 max-w-3xl text-[15px] text-slate-500">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <template v-if="period === 'range'">
                    <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Chọn nhanh kỳ" data-testid="report-quick-range">
                        <button
                            v-for="[p, label] in quick"
                            :key="p"
                            type="button"
                            :class="cn('rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900 sm:px-3.5', active === p && 'bg-white font-semibold text-slate-900 shadow-sm')"
                            @click="emit('update:range', presetRange(p))"
                        >
                            {{ label }}
                        </button>
                        <button type="button" :class="cn('rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900 sm:px-3.5', active === 'custom' && 'bg-white font-semibold text-slate-900 shadow-sm')" @click="custom">Tùy chọn</button>
                    </div>
                    <div ref="picker">
                        <DateRangeInput :from="from" :to="to" aria-label="Khoảng ngày" data-testid="report-range" @update:from="(v) => emit('update:range', { from: v, to })" @update:to="(v) => emit('update:range', { from, to: v })" />
                    </div>
                </template>
                <label v-else-if="period === 'date'" class="flex items-center gap-2 text-[14px]">
                    <span class="text-slate-500">Tồn đến hết ngày</span>
                    <DateInput :model-value="date" :max="max" class="w-40" aria-label="Ngày xem tồn" data-testid="report-date" @update:model-value="(v) => v && emit('update:date', v)" />
                </label>
                <slot name="actions" />
                <button
                    type="button"
                    class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"
                    :disabled="exporting || !canExport"
                    :data-testid="exportTestid"
                    @click="emit('export')"
                >
                    <Download class="size-4" /> {{ exporting ? 'Đang xuất…' : exportLabel }}
                </button>
            </div>
        </div>
        <nav v-if="siblings.length > 1" class="mt-5 flex flex-wrap gap-1.5" aria-label="Báo cáo cùng nhóm" data-testid="report-siblings">
            <RouterLink
                v-for="l in siblings"
                :key="l.title"
                :to="target(l)"
                :class="cn('shrink-0 whitespace-nowrap rounded-[10px] px-3 py-2 text-[13px]', isCurrent(l) ? 'bg-blue-600 font-medium text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700')"
            >
                {{ l.title }}
            </RouterLink>
        </nav>
    </div>
</template>
