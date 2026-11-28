<script setup lang="ts">
import { X } from "lucide-vue-next";
import { onBeforeUnmount, provide, watch, type Component } from "vue";
import { cn } from "@/shared/lib/utils";

/**
 * Popup in the Tailwind mockup style: 16px corners, soft shadow, header with an optional icon, grey footer.
 * Fields inside (Input, Select, Button, raw textarea/select) take the tall 10px-corner look via `soft-fields`.
 */
const open = defineModel<boolean>("open", { required: true });
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        size?: "md" | "lg" | "xl";
        icon?: Component;
        tone?: string;
    }>(),
    {
        size: "md",
        tone: "bg-blue-50 text-blue-600",
    },
);
const widths = {
    md: "max-w-[480px]",
    lg: "max-w-2xl",
    xl: "max-w-4xl",
} as const;
provide("soft-fields", true);
provide("soft-dialog", true);

function onKey(e: KeyboardEvent) {
    if (e.key === "Escape") open.value = false;
}
watch(
    open,
    (v) =>
        v
            ? window.addEventListener("keydown", onKey)
            : window.removeEventListener("keydown", onKey),
    { immediate: true },
);
onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
            enter-active-class="transition duration-150 ease-out"
            leave-active-class="transition duration-100 ease-in"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 pt-[10vh] backdrop-blur-[2px]"
                @mousedown.self="open = false"
            >
                <div
                    role="dialog"
                    aria-modal="true"
                    :aria-label="title"
                    :class="
                        cn(
                            'w-full overflow-hidden rounded-[16px] border border-slate-200/80 bg-white shadow-[0_24px_64px_-12px_rgba(15,23,42,0.28)]',
                            widths[size],
                        )
                    "
                >
                    <div class="flex items-start gap-3.5 px-6 pb-4 pt-5">
                        <span
                            v-if="icon"
                            :class="
                                cn(
                                    'grid size-10 shrink-0 place-items-center rounded-[11px]',
                                    tone,
                                )
                            "
                            ><component :is="icon" class="size-5"
                        /></span>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <h2
                                class="text-[18px] font-semibold leading-6 text-slate-900"
                            >
                                {{ title }}
                            </h2>
                            <p
                                v-if="description"
                                class="mt-1 text-[14px] leading-5 text-slate-500"
                            >
                                {{ description }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="-mr-2 -mt-1 grid size-9 shrink-0 place-items-center rounded-[10px] text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                            aria-label="Đóng"
                            @click="open = false"
                        >
                            <X class="size-[18px]" />
                        </button>
                    </div>
                    <div
                        class="dialog-body max-h-[70vh] overflow-y-auto border-t border-slate-100 px-6 py-5 text-[14px] text-slate-700 [&_textarea]:rounded-[10px] [&_textarea]:border-slate-200 [&_textarea]:px-3.5 [&_textarea]:py-2.5 [&_textarea]:text-[14px] [&_textarea]:shadow-none [&_fieldset]:rounded-[12px] [&_fieldset]:border-slate-200 [&_input[type=checkbox]]:accent-blue-600"
                    >
                        <slot />
                    </div>
                    <div
                        v-if="$slots.footer"
                        class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
