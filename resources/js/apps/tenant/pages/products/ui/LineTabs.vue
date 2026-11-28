<script setup lang="ts">
import { cn, formatNumber } from '@/shared/lib/utils';

/** Tabs on top of a list card (mẫu 28/11): blue underline, count after each label. */
defineProps<{ tabs: { value: string; label: string; count?: number | null }[] }>();
const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex gap-7 overflow-x-auto border-b border-slate-100 px-5" role="tablist">
        <button
            v-for="t in tabs"
            :key="t.value"
            type="button"
            role="tab"
            :aria-selected="model === t.value"
            :class="cn('-mb-px flex shrink-0 items-center whitespace-nowrap border-b-2 px-1 pb-3 pt-4 text-[14px]', model === t.value ? 'border-blue-600 font-semibold text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-900')"
            @click="model = t.value"
        >
            {{ t.label }}
            <span v-if="t.count !== undefined && t.count !== null" :class="cn('ml-1.5 rounded-md px-1.5 text-[12px] tabular-nums', model === t.value ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500')">{{ formatNumber(t.count) }}</span>
        </button>
    </div>
</template>
