<script setup lang="ts">
/** Head of the Thu chi pages (mẫu Tailwind 28/11): menu name, big title, one-line description, actions on the right. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[14px] text-slate-500">Thu chi</p>
            <h1 class="mt-1 text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]" data-testid="cash-title">{{ title }}</h1>
            <p v-if="description" class="mt-1 max-w-3xl text-[15px] text-slate-500">{{ description }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-3"><slot /></div>
    </div>
</template>
