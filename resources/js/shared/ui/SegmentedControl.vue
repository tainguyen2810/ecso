<script setup lang="ts" generic="T extends string">
import type { Component } from "vue";
import { cn } from "@/shared/lib/utils";

/** Two to four choices in one grey track (Cá nhân / Doanh nghiệp, % / Số tiền). `full` stretches it to the row. */
const model = defineModel<T>({ required: true });
defineProps<{
    options: { value: T; label: string; icon?: Component }[];
    full?: boolean;
    disabled?: boolean;
    class?: string;
}>();
</script>

<template>
    <div
        role="radiogroup"
        :class="
            cn(
                'flex h-11 items-center rounded-[10px] bg-slate-100 p-1',
                full ? 'w-full' : 'w-fit',
                disabled && 'opacity-60',
                $props.class,
            )
        "
    >
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            role="radio"
            :aria-checked="model === o.value"
            :disabled="disabled"
            :class="
                cn(
                    'flex h-full items-center justify-center gap-1.5 whitespace-nowrap rounded-[8px] px-3.5 text-[14px] transition-colors [&_svg]:size-4',
                    full && 'flex-1',
                    model === o.value
                        ? 'bg-white font-semibold text-slate-900 shadow-sm'
                        : 'text-slate-500 hover:text-slate-800',
                )
            "
            @click="model = o.value"
        >
            <component :is="o.icon" v-if="o.icon" />{{ o.label }}
        </button>
    </div>
</template>
