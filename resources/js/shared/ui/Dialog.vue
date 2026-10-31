<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { onBeforeUnmount, watch } from 'vue';

const open = defineModel<boolean>('open', { required: true });
withDefaults(defineProps<{ title: string; description?: string; size?: 'md' | 'lg' | 'xl' }>(), { size: 'md' });
const widths = { md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl' } as const;

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') open.value = false;
}
watch(open, (v) => (v ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-[12vh]" @mousedown.self="open = false">
            <div role="dialog" aria-modal="true" :class="['w-full rounded-lg border bg-card shadow-xl', widths[size]]">
                <div class="flex items-start justify-between border-b px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold">{{ title }}</h2>
                        <p v-if="description" class="mt-0.5 text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                    <button class="rounded p-1 text-muted-foreground hover:bg-muted" aria-label="Đóng" @click="open = false"><X class="size-4" /></button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto px-5 py-4"><slot /></div>
                <div v-if="$slots.footer" class="flex justify-end gap-2 border-t px-5 py-3"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
