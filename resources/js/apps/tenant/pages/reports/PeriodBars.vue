<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/shared/lib/utils';
import { short } from './dashboard';

/**
 * Small bar chart of the report side column (mẫu 28/11): one bar per day for a short period, one per week (Tuần 1, 2…)
 * for a longer one, the amount above each bar.
 */
const props = withDefaults(defineProps<{ days: { day: string; value: number }[]; color?: string }>(), { color: 'bg-blue-600' });

const dm = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;
const bars = computed(() => {
    if (props.days.length <= 10) return props.days.map((d) => ({ key: d.day, label: dm(d.day), value: d.value, title: `${dm(d.day)}: ${formatMoney(d.value)}` }));
    const out: { key: string; label: string; value: number; title: string }[] = [];
    for (let i = 0; i < props.days.length; i += 7) {
        const chunk = props.days.slice(i, i + 7);
        const value = chunk.reduce((s, d) => s + d.value, 0);
        const span = `${dm(chunk[0]!.day)} – ${dm(chunk[chunk.length - 1]!.day)}`;
        out.push({ key: chunk[0]!.day, label: `Tuần ${out.length + 1}`, value, title: `${span}: ${formatMoney(value)}` });
    }
    return out;
});
const max = computed(() => Math.max(...bars.value.map((b) => b.value), 0));
</script>

<template>
    <div class="px-5 pb-4 pt-5" data-testid="period-bars">
        <div class="flex h-40 items-end gap-3">
            <div v-for="b in bars" :key="b.key" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5" :title="b.title">
                <span v-if="b.value" class="whitespace-nowrap text-[11px] font-medium tabular-nums text-slate-500">{{ short(b.value) }}</span>
                <div :class="b.value ? `w-full max-w-9 rounded-t-[6px] ${color}` : 'h-[2px] w-full max-w-9 rounded bg-slate-100'" :style="b.value ? { height: `${Math.max(4, (b.value / (max || 1)) * 100)}%` } : undefined" />
            </div>
        </div>
        <div class="mt-2 flex gap-3 border-t border-slate-100 pt-2">
            <span v-for="b in bars" :key="b.key" class="min-w-0 flex-1 truncate text-center text-[11px] text-slate-400">{{ b.label }}</span>
        </div>
    </div>
</template>
