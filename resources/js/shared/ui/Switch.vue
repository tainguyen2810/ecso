<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** On/off switch with its label and an optional hint underneath (settings pages). */
defineProps<{ label: string; hint?: string; disabled?: boolean }>();
const model = defineModel<boolean>({ required: true });
</script>

<template>
    <label :class="cn('flex cursor-pointer items-start gap-3 py-2.5', disabled && 'cursor-not-allowed opacity-60')">
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :aria-label="label"
            :disabled="disabled"
            :class="cn('relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition-colors', model ? 'bg-primary' : 'bg-slate-300')"
            @click="model = !model"
        >
            <span :class="cn('absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', model ? 'left-[18px]' : 'left-0.5')" />
        </button>
        <span class="text-sm">
            {{ label }}
            <span v-if="hint" class="block text-xs text-muted-foreground">{{ hint }}</span>
        </span>
    </label>
</template>
