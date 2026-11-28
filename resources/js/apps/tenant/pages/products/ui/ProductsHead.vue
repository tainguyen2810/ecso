<script setup lang="ts">
/** Head of the Sản phẩm pages (mẫu Tailwind 28/11): menu name, big title, one-line description, quick links, actions on the right. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[14px] text-slate-500">Sản phẩm</p>
            <h1 class="mt-1 text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="products-title">{{ title }}</h1>
            <p v-if="description" class="mt-1 max-w-4xl text-[15px] text-slate-500">{{ description }}</p>
            <div v-if="$slots.links" class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-[14px] font-medium text-blue-700"><slot name="links" /></div>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-3"><slot /></div>
    </div>
</template>
