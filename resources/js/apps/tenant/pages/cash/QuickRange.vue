<script setup lang="ts">
import { computed, ref } from 'vue';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';
import { cn } from '@/shared/lib/utils';
import { presetRange, type Preset } from '../reports/range';

/** Quick periods (Hôm nay … Tùy chọn) and the date range picker, as on the report pages. */
const from = defineModel<string>('from', { required: true });
const to = defineModel<string>('to', { required: true });

const quick: [Preset, string][] = [
    ['today', 'Hôm nay'],
    ['7d', '7 ngày'],
    ['month', 'Tháng này'],
    ['last-month', 'Tháng trước'],
];
const active = computed<Preset | 'custom'>(() => quick.find(([p]) => {
    const r = presetRange(p);
    return r.from === from.value && r.to === to.value;
})?.[0] ?? 'custom');
function pick(p: Preset) {
    const r = presetRange(p);
    from.value = r.from;
    to.value = r.to;
}
const picker = ref<HTMLElement | null>(null);
const custom = () => picker.value?.querySelector<HTMLElement>('button, input')?.click();
const pill = (on: boolean) => cn('whitespace-nowrap rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900 sm:px-3.5', on && 'bg-white font-semibold text-slate-900 shadow-sm');
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Chọn nhanh kỳ" data-testid="cash-quick-range">
            <button v-for="[p, label] in quick" :key="p" type="button" :class="pill(active === p)" @click="pick(p)">{{ label }}</button>
            <button type="button" :class="pill(active === 'custom')" @click="custom">Tùy chọn</button>
        </div>
        <div ref="picker"><DateRangeInput v-model:from="from" v-model:to="to" aria-label="Khoảng ngày" data-testid="cash-range" /></div>
    </div>
</template>
