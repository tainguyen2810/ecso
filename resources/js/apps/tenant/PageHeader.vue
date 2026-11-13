<script setup lang="ts">
import { ChevronLeft } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';

/** Page title row: optional back link above, description (prop or #description slot), main buttons on the right. */
defineProps<{ title: string; description?: string; back?: { to: RouteLocationRaw; label: string } }>();
</script>

<template>
    <RouterLink v-if="back" :to="back.to" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> {{ back.label }}</RouterLink>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
            <p v-if="description || $slots.description" class="mt-1 text-sm text-muted-foreground"><slot name="description">{{ description }}</slot></p>
            <!-- Secondary actions as a row of links under the title (Sapo style); main buttons stay on the right. -->
            <div v-if="$slots.links" class="mt-1.5 flex flex-wrap items-center gap-x-5 gap-y-1"><slot name="links" /></div>
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
