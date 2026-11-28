<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Boxes, Download, PackagePlus, TrendingUp, UsersRound, Wallet, type LucideIcon } from 'lucide-vue-next';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { reportGroups, type ReportGroupKey } from './groups';
import { presetRange, type Preset } from './range';

/**
 * Frame of the five report menus (mẫu 28/11): breadcrumb, title, quick periods, date range, "Xuất báo cáo" and the
 * five menus as tabs. The period lives in the URL (?from=&to=), so switching tab keeps it.
 */
const props = defineProps<{ group: ReportGroupKey; description: string; from: string; to: string; exporting?: boolean; canExport?: boolean }>();
const emit = defineEmits<{ 'update:range': [range: { from: string; to: string }]; export: [] }>();

const route = useRoute();
const tenant = useTenantStore();

const tabIcons: Record<ReportGroupKey, LucideIcon> = { sales: TrendingUp, purchases: PackagePlus, stock: Boxes, finance: Wallet, customers: UsersRound };
const tabLabels: Record<ReportGroupKey, string> = { sales: 'Bán hàng', purchases: 'Nhập hàng', stock: 'Kho', finance: 'Tài chính', customers: 'Khách hàng' };
const tabs = computed(() => (Object.keys(reportGroups) as ReportGroupKey[]).filter((g) => tenant.can(reportGroups[g].permission)));

const quick: [Preset, string][] = [
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
        <p class="text-[13px] text-muted-foreground">Báo cáo / {{ tabLabels[group] }}</p>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900" data-testid="report-title">{{ reportGroups[group].title }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="flex rounded-[10px] bg-slate-100 p-1 text-sm" role="group" aria-label="Chọn nhanh kỳ" data-testid="report-quick-range">
                    <button
                        v-for="[p, label] in quick"
                        :key="p"
                        type="button"
                        :class="cn('rounded-[7px] px-3 py-1.5 text-slate-600 hover:text-slate-900', active === p && 'bg-white font-medium text-slate-900 shadow-sm')"
                        @click="emit('update:range', presetRange(p))"
                    >
                        {{ label }}
                    </button>
                    <button type="button" :class="cn('rounded-[7px] px-3 py-1.5 text-slate-600 hover:text-slate-900', active === 'custom' && 'bg-white font-medium text-slate-900 shadow-sm')" @click="custom">Tùy chọn</button>
                </div>
                <div ref="picker">
                    <DateRangeInput :from="from" :to="to" aria-label="Khoảng ngày" data-testid="report-range" @update:from="(v) => emit('update:range', { from: v, to })" @update:to="(v) => emit('update:range', { from, to: v })" />
                </div>
                <button
                    v-if="canExport !== false"
                    type="button"
                    class="inline-flex h-9 items-center gap-2 rounded-[10px] bg-blue-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"
                    :disabled="exporting"
                    data-testid="report-export"
                    @click="emit('export')"
                >
                    <Download class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất báo cáo' }}
                </button>
            </div>
        </div>
        <nav class="mt-5 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Nhóm báo cáo" data-testid="report-tabs">
            <RouterLink
                v-for="g in tabs"
                :key="g"
                :to="{ name: 'report-group', params: { group: g }, query: { from: route.query.from, to: route.query.to } }"
                :class="cn('-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm', g === group ? 'border-blue-600 font-semibold text-blue-600' : 'border-transparent text-slate-600 hover:text-slate-900')"
            >
                <component :is="tabIcons[g]" class="size-4" /> {{ tabLabels[g] }}
            </RouterLink>
        </nav>
    </div>
</template>
