<script setup lang="ts">
import { cn } from "@/shared/lib/utils";
import { SOFT_INVALID, useSoft } from "./soft";

defineOptions({ inheritAttrs: false });
const model = defineModel<string | null>();
defineProps<{ class?: string; invalid?: boolean }>();
const soft = useSoft();
</script>

<template>
    <textarea
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'flex min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                soft &&
                    'rounded-[10px] border-slate-200 bg-white px-3.5 py-2.5 text-[15px] shadow-none placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-4 focus-visible:ring-blue-100',
                invalid && (soft ? SOFT_INVALID : 'border-destructive'),
                $props.class,
            )
        "
    />
</template>
