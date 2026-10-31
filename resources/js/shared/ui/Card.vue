<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

defineProps<{ class?: string; title?: string; description?: string }>();
</script>

<template>
    <div :class="cn('rounded-lg border bg-card shadow-xs', $props.class)">
        <div v-if="title || $slots.actions" class="flex items-start justify-between gap-4 border-b px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
