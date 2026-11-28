<script setup lang="ts">
import { inject } from 'vue';
import { cn } from '@/shared/lib/utils';

defineOptions({ inheritAttrs: false });
const model = defineModel<string>();
defineProps<{ options: { value: string; label: string; disabled?: boolean }[]; class?: string }>();
const soft = inject<boolean>('soft-cards', false) || inject<boolean>('soft-fields', false);
</script>

<template>
    <select
        v-model="model"
        v-bind="$attrs"
        :class="cn('h-9 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', soft && 'select-soft h-11 rounded-[10px] border-slate-200 bg-white pl-3.5 pr-10 text-[15px] focus-visible:border-blue-500 focus-visible:ring-4 focus-visible:ring-blue-100', $props.class)"
    >
        <option v-for="o in options" :key="o.value" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
    </select>
</template>
