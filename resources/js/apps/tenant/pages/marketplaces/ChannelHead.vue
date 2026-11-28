<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';

/** Head of the Kênh bán pages (mẫu Tailwind 28/11): path, big title with the marketplace logo, one-line description, actions. */
defineProps<{ title: string; description?: string; marketplace?: string | null; crumbs?: { label: string; to?: RouteLocationRaw }[] }>();
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="flex min-w-0 items-center gap-4">
            <MarketplaceLogo v-if="marketplace" :marketplace="marketplace" size="lg" class="!size-12 !rounded-[14px] sm:!size-14" />
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-1 text-[14px] text-slate-500">
                    <RouterLink to="/marketplaces" class="hover:text-blue-600">Kênh bán</RouterLink>
                    <template v-for="c in crumbs ?? []" :key="c.label">
                        <span class="text-slate-300">›</span>
                        <RouterLink v-if="c.to" :to="c.to" class="hover:text-blue-600">{{ c.label }}</RouterLink>
                        <span v-else>{{ c.label }}</span>
                    </template>
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1 class="truncate text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="channel-title">{{ title }}</h1>
                    <slot name="chips" />
                </div>
                <p v-if="description" class="mt-1 max-w-4xl text-[15px] text-slate-500">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-3"><slot /></div>
    </div>
</template>
