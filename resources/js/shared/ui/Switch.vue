<script setup lang="ts">
import { inject } from "vue";
import { cn } from "@/shared/lib/utils";
import { useSoft } from "./soft";

/**
 * On/off switch with its label and an optional hint underneath (settings pages). Inside Cấu hình (`soft-cards`) it takes
 * the mockup layout: text on the left, a larger switch on the right.
 */
defineProps<{ label: string; hint?: string; disabled?: boolean }>();
const model = defineModel<boolean>({ required: true });
const soft = inject<boolean>("soft-cards", false);
const softField = useSoft();
</script>

<template>
    <label
        v-if="soft"
        :class="
            cn(
                'flex cursor-pointer items-start justify-between gap-6 py-4',
                disabled && 'cursor-not-allowed opacity-60',
            )
        "
    >
        <span class="min-w-0">
            <span class="block text-[15px] font-medium text-slate-900">{{
                label
            }}</span>
            <span v-if="hint" class="mt-0.5 block text-[13px] text-slate-500">{{
                hint
            }}</span>
        </span>
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :aria-label="label"
            :disabled="disabled"
            :class="
                cn(
                    'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                    model ? 'bg-blue-600' : 'bg-slate-200',
                )
            "
            @click="model = !model"
        >
            <span
                :class="
                    cn(
                        'absolute top-0.5 size-5 rounded-full bg-white shadow transition-all',
                        model ? 'left-[22px]' : 'left-0.5',
                    )
                "
            />
        </button>
    </label>
    <label
        v-else
        :class="
            cn(
                'flex cursor-pointer items-start gap-3 py-2.5',
                disabled && 'cursor-not-allowed opacity-60',
            )
        "
    >
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :aria-label="label"
            :disabled="disabled"
            :class="
                cn(
                    softField
                        ? 'relative h-6 w-11 shrink-0 rounded-full transition-colors'
                        : 'relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition-colors',
                    model
                        ? softField
                            ? 'bg-blue-600'
                            : 'bg-primary'
                        : softField
                          ? 'bg-slate-200'
                          : 'bg-slate-300',
                )
            "
            @click="model = !model"
        >
            <span
                :class="
                    cn(
                        softField
                            ? 'absolute top-0.5 size-5 rounded-full bg-white shadow transition-all'
                            : 'absolute top-0.5 size-4 rounded-full bg-white shadow transition-all',
                        model
                            ? softField
                                ? 'left-[22px]'
                                : 'left-[18px]'
                            : 'left-0.5',
                    )
                "
            />
        </button>
        <span
            :class="softField ? 'pt-0.5 text-[14px] text-slate-700' : 'text-sm'"
        >
            {{ label }}
            <span v-if="hint" class="block text-xs text-muted-foreground">{{
                hint
            }}</span>
        </span>
    </label>
</template>
