<script setup lang="ts">
import { computed, ref } from 'vue';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';
import { presetRange, type Preset } from '../reports/range';
import { pill } from './ui';

/** Quick periods (Hôm nay … Tùy chọn) and the date range picker of the Vận chuyển pages. */
const from = defineModel<string>('from', { required: true });
const to = defineModel<string>('to', { required: true });

const quick: [Preset, string][] = [
    ['today', 'Hôm nay'],
    ['7d', '7 ngày'],
    ['30d', '30 ngày'],
    ['month', 'Tháng này'],
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
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Chọn nhanh kỳ" data-testid="shipping-quick-range">
            <button v-for="[p, label] in quick" :key="p" type="button" :class="pill(active === p)" @click="pick(p)">{{ label }}</button>
            <button type="button" :class="pill(active === 'custom')" @click="custom">Tùy chọn</button>
        </div>
        <div ref="picker"><DateRangeInput v-model:from="from" v-model:to="to" aria-label="Khoảng ngày" /></div>
    </div>
</template>
