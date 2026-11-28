<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { inject, onBeforeUnmount, provide, watch } from 'vue';
import { cn } from '@/shared/lib/utils';

const open = defineModel<boolean>('open', { required: true });
withDefaults(defineProps<{ title: string; description?: string; size?: 'md' | 'lg' | 'xl' }>(), { size: 'md' });
const widths = { md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl' } as const;
// Inside a page that provides `soft-cards` (mẫu Tailwind 28/11): 16px corners, roomier header and footer, and the
// buttons inside take the 44px style (see Button.vue).
const soft = inject<boolean>('soft-cards', false);
provide('soft-dialog', soft);

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') open.value = false;
}
watch(open, (v) => (v ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" :class="cn('fixed inset-0 z-50 flex items-start justify-center p-4 pt-[12vh]', soft ? 'bg-slate-900/40 backdrop-blur-[2px]' : 'bg-black/40')" @mousedown.self="open = false">
            <div
                role="dialog"
                aria-modal="true"
                :class="cn('w-full', soft ? 'rounded-[16px] border border-slate-200/80 bg-white shadow-[0_24px_48px_-12px_rgba(15,23,42,0.28)]' : 'rounded-lg border bg-card shadow-xl', widths[size])"
            >
                <div :class="cn('flex items-start justify-between gap-4 border-b', soft ? 'border-slate-100 px-6 py-5' : 'px-5 py-4')">
                    <div>
                        <h2 :class="soft ? 'text-[18px] font-semibold text-slate-900' : 'text-base font-semibold'">{{ title }}</h2>
                        <p v-if="description" :class="soft ? 'mt-1 text-[14px] text-slate-500' : 'mt-0.5 text-sm text-muted-foreground'">{{ description }}</p>
                    </div>
                    <button :class="soft ? '-mr-2 -mt-1 grid size-9 shrink-0 place-items-center rounded-[10px] text-slate-400 hover:bg-slate-100 hover:text-slate-700' : 'rounded p-1 text-muted-foreground hover:bg-muted'" aria-label="Đóng" @click="open = false">
                        <X :class="soft ? 'size-5' : 'size-4'" />
                    </button>
                </div>
                <div :class="cn('max-h-[70vh] overflow-y-auto', soft ? 'px-6 py-5 text-[14px] text-slate-700' : 'px-5 py-4')"><slot /></div>
                <div v-if="$slots.footer" :class="cn('flex justify-end border-t', soft ? 'gap-3 rounded-b-[16px] border-slate-100 bg-slate-50/70 px-6 py-4' : 'gap-2 px-5 py-3')"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
