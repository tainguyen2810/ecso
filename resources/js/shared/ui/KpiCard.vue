<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/shared/lib/utils';
import { toneClasses, type Tone } from '@/shared/lib/tones';
import IconBubble from './IconBubble.vue';

/**
 * Stand-alone figure card for a row of KPIs (same look as StatTile, own border). `tone` colours the figure only when
 * it means something; `active` marks a card that is the current filter. `value` null = no data (shows —).
 */
const props = withDefaults(
    defineProps<{ label: string; value: string | null; icon?: Component; tone?: Tone; active?: boolean; class?: string }>(),
    { tone: 'slate', active: false },
);
</script>

<template>
    <div :class="cn('flex items-center gap-3 rounded-lg border bg-card px-4 py-3.5 shadow-xs', active && 'border-primary/40 bg-blue-50/60 ring-1 ring-primary/30', props.class)">
        <IconBubble v-if="icon" :icon="icon" :tone="tone === 'slate' ? 'blue' : tone" />
        <div class="min-w-0 flex-1">
            <p class="flex items-center gap-1 text-xs text-muted-foreground"><slot name="label">{{ label }}</slot></p>
            <p :class="cn('whitespace-nowrap text-lg font-bold tabular-nums 2xl:text-xl', value === null ? 'text-muted-foreground/60' : toneClasses[tone].value)"><slot name="value">{{ value ?? '—' }}</slot></p>
            <p v-if="$slots.default" class="text-[11px] text-muted-foreground"><slot /></p>
        </div>
    </div>
</template>
