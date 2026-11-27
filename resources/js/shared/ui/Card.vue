<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** Sapo-like card: white, 3px corners, soft shadow, no outline. Section heading is bold uppercase by default (docs/DESIGN-SYSTEM.md); `:caps="false"` for a plain title. */
withDefaults(defineProps<{ class?: string; title?: string; description?: string; caps?: boolean }>(), { caps: true });
</script>

<template>
    <div :class="cn('rounded-lg bg-card shadow-card', $props.class)">
        <div v-if="title || $slots.actions" :class="cn('flex min-h-[52px] justify-between gap-4 border-b px-4', caps ? 'items-center py-3' : 'items-center py-3')">
            <div>
                <h3 :class="caps ? 'text-[13px] font-bold uppercase tracking-wide' : 'text-[15px] font-medium'">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
