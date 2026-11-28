<script setup lang="ts">
import { inject, type Component } from 'vue';
import { cn } from '@/shared/lib/utils';

/**
 * Sapo-like card: white, 3px corners, soft shadow, no outline. Section heading is bold uppercase by default (docs/DESIGN-SYSTEM.md); `:caps="false"` for a plain title.
 * Inside a frame that provides `soft-cards` (Cấu hình), cards take the report style: 14px corners, light border, plain title.
 */
withDefaults(defineProps<{ class?: string; title?: string; description?: string; caps?: boolean; icon?: Component; iconTone?: string }>(), { caps: true, icon: undefined, iconTone: 'bg-slate-100 text-slate-600' });
const soft = inject<boolean>('soft-cards', false);
</script>

<template>
    <div :class="cn(soft ? 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]' : 'rounded-lg bg-card shadow-card', $props.class)">
        <div v-if="title || $slots.actions" :class="cn('flex min-h-[52px] items-center justify-between gap-4 border-b py-3', soft ? 'border-slate-100 px-5 py-4' : 'px-4')">
            <div class="flex min-w-0 items-center gap-3">
                <span v-if="icon" :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTone)"><component :is="icon" class="size-5" :stroke-width="1.9" /></span>
                <div class="min-w-0">
                <h3 :class="soft ? 'font-semibold text-slate-900' : caps ? 'text-[13px] font-bold uppercase tracking-wide' : 'text-[15px] font-medium'">{{ title }}</h3>
                <p v-if="description" :class="soft ? 'mt-0.5 text-[13px] text-slate-500' : 'mt-0.5 text-xs text-muted-foreground'">{{ description }}</p>
                </div>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
