<script setup lang="ts">
import type { Component } from 'vue';
import { toneClasses, type Tone } from '@/shared/lib/tones';
import IconBubble from './IconBubble.vue';

/** Icon bubble + label + big figure, as in the "Kết quả kinh doanh" row. `value` null = no data (shows —). */
withDefaults(defineProps<{ icon: Component; label: string; value: string | null; tone?: Tone; to?: string | null }>(), { tone: 'blue', to: null });
</script>

<template>
    <component :is="to ? 'RouterLink' : 'div'" :to="to ?? undefined" class="flex items-center gap-3 px-5 py-4" :class="to && 'hover:bg-muted/40'">
        <IconBubble :icon="icon" :tone="tone" size="lg" />
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ label }}</p>
            <p class="whitespace-nowrap text-lg font-bold tabular-nums 2xl:text-xl" :class="value === null ? 'text-muted-foreground/60' : toneClasses[tone].value">{{ value ?? '—' }}</p>
            <p v-if="$slots.default" class="text-[11px] text-muted-foreground"><slot /></p>
        </div>
    </component>
</template>
