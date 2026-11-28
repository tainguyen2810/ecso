<script setup lang="ts">
import { cn } from "@/shared/lib/utils";
import { SOFT_FIELD, SOFT_INVALID, useSoft } from "./soft";

defineOptions({ inheritAttrs: false });
const model = defineModel<string>();
defineProps<{
    options: { value: string; label: string; disabled?: boolean }[];
    class?: string;
    invalid?: boolean;
}>();
const soft = useSoft();
</script>

<template>
    <select
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'h-9 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                soft && ['select-soft pl-3.5 pr-10', SOFT_FIELD],
                invalid && (soft ? SOFT_INVALID : 'border-destructive'),
                $props.class,
            )
        "
    >
        <option
            v-for="o in options"
            :key="o.value"
            :value="o.value"
            :disabled="o.disabled"
        >
            {{ o.label }}
        </option>
    </select>
</template>
