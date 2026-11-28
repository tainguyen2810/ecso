<script setup lang="ts">
import { ArrowRight, TriangleAlert } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';

/** Amber line under the headline figures: what needs a look, with a link to it. */
defineProps<{ to?: RouteLocationRaw; action?: string }>();
</script>

<template>
    <div class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-[12px] border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900" data-testid="report-alert">
        <TriangleAlert class="size-4 shrink-0 text-amber-600" />
        <span class="min-w-0"><slot /></span>
        <RouterLink v-if="to && action" :to="to" class="ml-auto inline-flex items-center gap-1 font-semibold text-amber-700 hover:underline">{{ action }} <ArrowRight class="size-4" /></RouterLink>
    </div>
</template>
