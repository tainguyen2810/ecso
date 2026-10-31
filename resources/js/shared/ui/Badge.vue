<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

const tones = {
    default: 'bg-secondary text-secondary-foreground',
    success: 'bg-success/12 text-success',
    warning: 'bg-warning/15 text-[oklch(0.5_0.13_70)]',
    danger: 'bg-destructive/10 text-destructive',
    muted: 'bg-muted text-muted-foreground',
} as const;

withDefaults(defineProps<{ tone?: keyof typeof tones }>(), { tone: 'default' });
</script>

<template>
    <span :class="cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])"><slot /></span>
</template>
