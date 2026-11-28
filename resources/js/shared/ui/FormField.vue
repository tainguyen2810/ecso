<script setup lang="ts">
import { useSoft } from "./soft";

/** Label, field, then the error (red) or a hint (grey). `optional` adds "· không bắt buộc", `required` a red star. */
defineProps<{
    label: string;
    for?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    optional?: boolean;
}>();
const soft = useSoft();
</script>

<template>
    <div class="space-y-1.5">
        <label
            :for="$props.for"
            :class="
                soft
                    ? 'block text-[13.5px] font-medium text-slate-700'
                    : 'text-sm font-medium leading-none text-slate-700'
            "
            >{{ label }}<span v-if="required" class="text-red-500">*</span
            ><span v-if="optional" class="font-normal text-slate-400">
                · không bắt buộc</span
            ></label
        >
        <slot />
        <p
            v-if="error"
            :class="
                soft ? 'text-[12.5px] text-red-600' : 'text-xs text-destructive'
            "
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            :class="
                soft
                    ? 'text-[12.5px] text-slate-400'
                    : 'text-xs text-muted-foreground'
            "
        >
            {{ hint }}
        </p>
    </div>
</template>
