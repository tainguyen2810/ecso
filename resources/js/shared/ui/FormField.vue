<script setup lang="ts">
import { inject } from 'vue';

defineProps<{ label: string; for?: string; error?: string; hint?: string }>();
const soft = inject<boolean>('soft-fields', false);
</script>

<template>
    <div class="space-y-1.5">
        <label :for="$props.for" :class="soft ? 'block text-[13.5px] font-medium text-slate-700' : 'text-sm font-medium leading-none text-slate-700'">{{ label }}</label>
        <slot />
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
