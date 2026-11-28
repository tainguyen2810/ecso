<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** Segmented choice (grey track, white pill on the chosen one), as on the report and Thu chi pages. */
defineProps<{ options: { value: string; label: string }[]; label?: string }>();
const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex max-w-full overflow-x-auto rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" :aria-label="label">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            :aria-pressed="model === o.value"
            :class="cn('whitespace-nowrap rounded-[9px] px-3.5 py-1.5', model === o.value ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
            @click="model = o.value"
        >
            {{ o.label }}
        </button>
    </div>
</template>
