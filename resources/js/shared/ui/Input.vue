<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

defineOptions({ inheritAttrs: false });
const model = defineModel<string | number>();
defineProps<{ class?: string; invalid?: boolean }>();
</script>

<template>
    <input
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                invalid && 'border-destructive focus-visible:ring-destructive',
                $props.class,
            )
        "
    />
</template>
