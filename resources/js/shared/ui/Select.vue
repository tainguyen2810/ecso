<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

defineOptions({ inheritAttrs: false });
const model = defineModel<string>();
defineProps<{ options: { value: string; label: string; disabled?: boolean }[]; class?: string }>();
</script>

<template>
    <select
        v-model="model"
        v-bind="$attrs"
        :class="cn('h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', $props.class)"
    >
        <option v-for="o in options" :key="o.value" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
    </select>
</template>
