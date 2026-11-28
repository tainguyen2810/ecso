<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/shared/lib/utils';
import { toneClasses, type Tone } from '@/shared/lib/tones';

const props = withDefaults(defineProps<{ icon: Component; tone?: Tone; size?: 'md' | 'lg'; class?: string }>(), { tone: 'blue', size: 'md' });
</script>

<template>
    <span :class="cn('flex shrink-0 items-center justify-center rounded-full', props.size === 'lg' ? 'size-11' : 'size-10', toneClasses[props.tone].bubble, props.class)">
        <component :is="icon" class="size-5" :stroke-width="1.75" />
    </span>
</template>
