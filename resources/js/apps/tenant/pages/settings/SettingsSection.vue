<script setup lang="ts">
/** One block of a settings page as a card: title and explanation on top, the fields below. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <section class="scroll-mt-4 rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div class="min-w-0">
                <h2 class="font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-[13px] text-slate-500">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div class="p-5"><slot /></div>
    </section>
</template>
