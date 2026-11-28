<script setup lang="ts">
import { inject } from 'vue';
import { cn } from '@/shared/lib/utils';

defineOptions({ inheritAttrs: false });
const model = defineModel<string | number | null>();
defineProps<{ class?: string; invalid?: boolean }>();
// Inside Cấu hình (`soft-cards`): taller fields with 10px corners, like the mockup.
const soft = inject<boolean>('soft-cards', false);
</script>

<template>
    <input
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                soft && 'h-11 rounded-[10px] border-slate-200 px-3.5 text-[15px]',
                invalid && 'border-destructive focus-visible:ring-destructive',
                $props.class,
            )
        "
    />
</template>
