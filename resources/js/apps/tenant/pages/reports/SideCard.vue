<script setup lang="ts">
/** Card of the right-hand column of a report menu: title, short description, optional badge and action, content. */
defineProps<{ title: string; description?: string; badge?: string | null }>();
</script>

<template>
    <section class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
        <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="text-[16px] font-semibold text-slate-900">{{ title }}</h3>
                <p v-if="description" class="mt-0.5 text-[13px] text-slate-500">{{ description }}</p>
            </div>
            <span v-if="badge" class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ badge }}</span>
            <slot name="actions" />
        </header>
        <slot />
    </section>
</template>
