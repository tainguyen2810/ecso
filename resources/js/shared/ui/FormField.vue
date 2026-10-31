<script setup lang="ts">
defineProps<{ label: string; for?: string; error?: string; hint?: string }>();
</script>

<template>
    <div class="space-y-1.5">
        <label :for="$props.for" class="text-sm font-medium leading-none">{{ label }}</label>
        <slot />
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
