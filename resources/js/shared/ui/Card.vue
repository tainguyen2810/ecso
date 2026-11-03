<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** Section heading is bold uppercase by default (docs/DESIGN-SYSTEM.md); `:caps="false"` for a plain title. */
withDefaults(defineProps<{ class?: string; title?: string; description?: string; caps?: boolean }>(), { caps: true });
</script>

<template>
    <div :class="cn('rounded-lg border bg-card shadow-xs', $props.class)">
        <div v-if="title || $slots.actions" :class="cn('flex justify-between gap-4 border-b px-5', caps ? 'items-center py-3' : 'items-start py-4')">
            <div>
                <h3 :class="caps ? 'text-[13px] font-bold uppercase tracking-wide' : 'text-sm font-semibold'">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
