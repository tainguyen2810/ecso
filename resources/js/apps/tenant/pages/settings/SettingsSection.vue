<script setup lang="ts">
/** One block of a settings page: title and explanation on the left, the fields in a card on the right. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <section class="grid gap-4 py-6 lg:grid-cols-[18rem_1fr] lg:gap-8">
        <div>
            <h2 class="font-semibold">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <div class="rounded-lg border bg-card p-5 shadow-xs"><slot /></div>
    </section>
</template>
