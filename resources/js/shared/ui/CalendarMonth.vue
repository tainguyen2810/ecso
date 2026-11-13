<script setup lang="ts">
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { isoDate, monthGrid, weekdayNames } from '@/shared/lib/dates';

/**
 * One month of the date pickers (docs/DESIGN-SYSTEM.md, "Chọn ngày"): Monday first, Sundays in red, today ringed,
 * the picked day filled. With rangeFrom/rangeTo it shades a range instead.
 */
const props = defineProps<{
    year: number;
    month: number;
    selected?: string;
    rangeFrom?: string;
    rangeTo?: string;
    min?: string;
    max?: string;
    hidePrev?: boolean;
    hideNext?: boolean;
}>();
const emit = defineEmits<{ pick: [iso: string]; hover: [iso: string | null]; prev: []; next: [] }>();

const today = isoDate(new Date());
const days = computed(() => monthGrid(props.year, props.month));
const disabled = (iso: string) => (!!props.min && iso < props.min) || (!!props.max && iso > props.max);

function cls(d: { iso: string; inMonth: boolean; sunday: boolean }) {
    const { selected, rangeFrom: from, rangeTo: to } = props;
    const isStart = !!from && d.iso === from;
    const isEnd = !!to && d.iso === to;
    const inside = !!from && !!to && d.iso > from && d.iso < to;
    const filled = d.inMonth && (d.iso === selected || isStart || isEnd);
    return cn(
        'h-8 text-[13px] tabular-nums transition-colors',
        !d.inMonth && 'text-slate-300',
        d.inMonth && d.sunday && !filled && 'text-red-600',
        d.inMonth && !filled && !inside && 'rounded-md hover:bg-slate-100',
        d.inMonth && d.iso === today && !filled && 'rounded-md font-semibold text-blue-600 ring-1 ring-inset ring-blue-500',
        d.inMonth && inside && 'bg-blue-50 text-blue-900',
        filled && 'bg-blue-600 font-semibold text-white',
        filled && !isStart && !isEnd && 'rounded-md',
        d.inMonth && isStart && (to && to !== from ? 'rounded-l-md' : 'rounded-md'),
        d.inMonth && isEnd && (from && from !== to ? 'rounded-r-md' : 'rounded-md'),
        disabled(d.iso) && 'pointer-events-none opacity-35',
    );
}
</script>

<template>
    <div class="w-[252px]">
        <div class="mb-1.5 flex items-center justify-between">
            <button type="button" :class="cn('rounded p-1 text-slate-500 hover:bg-slate-100', hidePrev && 'invisible')" aria-label="Tháng trước" @click="emit('prev')">
                <ChevronLeft class="size-4" />
            </button>
            <span class="text-sm font-semibold">Tháng {{ month + 1 }}, {{ year }}</span>
            <button type="button" :class="cn('rounded p-1 text-slate-500 hover:bg-slate-100', hideNext && 'invisible')" aria-label="Tháng sau" @click="emit('next')">
                <ChevronRight class="size-4" />
            </button>
        </div>
        <div class="grid grid-cols-7" @mouseleave="emit('hover', null)">
            <span v-for="w in weekdayNames" :key="w" class="pb-1 text-center text-[11px] font-semibold text-slate-400">{{ w }}</span>
            <template v-for="d in days" :key="d.iso">
                <button v-if="d.inMonth" type="button" :class="cls(d)" :data-date="d.iso" @click="emit('pick', d.iso)" @mouseenter="emit('hover', d.iso)">{{ d.day }}</button>
                <span v-else :class="cls(d)" class="flex items-center justify-center">{{ d.day }}</span>
            </template>
        </div>
    </div>
</template>
