<script setup lang="ts">
import { cn } from "@/shared/lib/utils";
import { SOFT_FIELD, SOFT_INVALID, useSoft } from "./soft";

defineOptions({ inheritAttrs: false });
const model = defineModel<string | number | null>();
/** `suffix`: a unit shown inside the field on the right (đ, %, ngày). */
defineProps<{ class?: string; invalid?: boolean; suffix?: string }>();
const soft = useSoft();
</script>

<template>
    <div v-if="suffix" class="relative w-full">
        <input
            v-model="model"
            v-bind="$attrs"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 pr-12 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                    soft && [SOFT_FIELD, 'px-3.5 pr-14'],
                    invalid &&
                        (soft
                            ? SOFT_INVALID
                            : 'border-destructive focus-visible:ring-destructive'),
                    $props.class,
                )
            "
        />
        <span
            class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[13px] text-slate-400"
            >{{ suffix }}</span
        >
    </div>
    <input
        v-else
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                soft && [SOFT_FIELD, 'px-3.5'],
                invalid &&
                    (soft
                        ? SOFT_INVALID
                        : 'border-destructive focus-visible:ring-destructive'),
                $props.class,
            )
        "
    />
</template>
