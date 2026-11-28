<script setup lang="ts">
import { inject } from 'vue';
import { ChevronLeft } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';

/**
 * Page title row: optional back link above, description (prop or #description slot), main buttons on the right.
 * Inside a page that calls useSoftPage(), it takes the mockup look: menu name above a big title.
 */
defineProps<{ title: string; description?: string; back?: { to: RouteLocationRaw; label: string } }>();
const section = inject<string | null>('soft-page', null);
</script>

<template>
    <template v-if="section">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-1.5 text-[14px] text-slate-500">
                    <span>{{ section }}</span>
                    <template v-if="back">
                        <span class="text-slate-300">/</span>
                        <RouterLink :to="back.to" class="inline-flex items-center gap-0.5 hover:text-blue-700"><ChevronLeft class="size-4" />{{ back.label }}</RouterLink>
                    </template>
                </p>
                <h1 class="mt-1 text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">{{ title }}</h1>
                <p v-if="description || $slots.description" class="mt-1 max-w-4xl text-[15px] text-slate-500"><slot name="description">{{ description }}</slot></p>
                <div v-if="$slots.links" class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5"><slot name="links" /></div>
            </div>
            <div class="flex flex-wrap items-center gap-3"><slot /></div>
        </div>
    </template>
    <template v-else>
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
</template>
