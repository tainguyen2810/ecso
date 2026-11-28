<script setup lang="ts">
import { ref } from 'vue';
import { Info } from 'lucide-vue-next';

/**
 * "ⓘ" next to a figure: on hover, focus or tap it explains how the figure is computed and where it comes from.
 * `title` is the formula line; the default slot holds the details.
 */
defineProps<{ title: string; label?: string }>();
const open = ref(false);
</script>

<template>
    <span class="relative inline-flex" @mouseenter="open = true" @mouseleave="open = false">
        <button
            type="button"
            class="grid size-4 place-items-center rounded-full text-muted-foreground hover:text-primary focus-visible:text-primary focus-visible:outline-none"
            :aria-label="label ?? `Cách tính: ${title}`"
            :aria-expanded="open"
            @click.stop="open = !open"
            @focus="open = true"
            @blur="open = false"
        >
            <Info class="size-3.5" />
        </button>
        <span
            v-if="open"
            role="tooltip"
            class="absolute left-0 top-6 z-30 w-72 rounded-lg border bg-card p-3 text-left text-xs font-normal normal-case leading-relaxed text-muted-foreground shadow-lg"
        >
            <span class="block font-semibold text-foreground">{{ title }}</span>
            <span v-if="$slots.default" class="mt-1 block"><slot /></span>
        </span>
    </span>
</template>
