<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** `underline`: list-page tabs (blue underline + count pill, docs/DESIGN-SYSTEM.md); default: segmented pills. */
withDefaults(defineProps<{ tabs: { value: string; label: string; count?: number | null }[]; variant?: 'pill' | 'underline' }>(), { variant: 'pill' });
const model = defineModel<string>({ required: true });
</script>

<template>
    <div v-if="variant === 'underline'" class="-mb-px flex gap-1 overflow-x-auto text-sm">
        <button
            v-for="t in tabs"
            :key="t.value"
            type="button"
            :class="cn('flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2.5 text-muted-foreground hover:text-foreground', model === t.value ? 'border-primary font-semibold text-primary' : 'border-transparent')"
            @click="model = t.value"
        >
            {{ t.label }}
            <span v-if="t.count !== undefined && t.count !== null" :class="cn('rounded-full px-1.5 text-[11px] font-semibold tabular-nums', model === t.value ? 'bg-blue-50 text-blue-600' : 'bg-muted text-muted-foreground')">{{ t.count }}</span>
        </button>
    </div>
    <div v-else class="flex flex-wrap gap-1 rounded-md bg-muted p-1 text-sm">
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
