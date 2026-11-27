<script setup lang="ts">
/** The marketplace's variation of a line ("Phân loại: 128GB"), on its own so a long product name never hides it. */
defineProps<{ name: string | null | undefined }>();
</script>

<template>
    <span v-if="name" class="inline-flex max-w-full items-center gap-1 rounded bg-blue-50 px-1.5 py-px text-xs text-blue-800 ring-1 ring-inset ring-blue-200" :title="`Phân loại: ${name}`" data-testid="variant-tag">
        <span class="shrink-0 text-blue-600/80">Phân loại:</span><span class="truncate font-semibold">{{ name }}</span>
    </span>
</template>
