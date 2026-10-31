<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

defineProps<{ tabs: { value: string; label: string; count?: number | null }[] }>();
const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex flex-wrap gap-1 rounded-md bg-muted p-1 text-sm">
        <button
            v-for="t in tabs"
            :key="t.value"
            type="button"
            :class="cn('rounded px-3 py-1 text-muted-foreground', model === t.value && 'bg-card font-medium text-foreground shadow-xs')"
            @click="model = t.value"
        >
            {{ t.label }} <span v-if="t.count !== undefined && t.count !== null" class="text-muted-foreground">{{ t.count }}</span>
        </button>
    </div>
</template>
