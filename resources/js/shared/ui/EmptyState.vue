<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ icon: Component; title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-secondary text-primary">
            <component :is="icon" class="size-6" />
        </div>
        <h3 class="text-sm font-semibold">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-md text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
