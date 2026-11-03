<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
            <!-- Secondary actions as a row of links under the title (Sapo style); main buttons stay on the right. -->
            <div v-if="$slots.links" class="mt-1.5 flex flex-wrap items-center gap-x-5 gap-y-1"><slot name="links" /></div>
        </div>
        <div class="flex items-center gap-2"><slot /></div>
    </div>
</template>
