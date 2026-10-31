<script setup lang="ts">
import { CheckCircle2, Info, XCircle } from 'lucide-vue-next';
import { useToast } from '@/shared/composables/useToast';

const { toasts, dismiss } = useToast();
const icons = { success: CheckCircle2, error: XCircle, info: Info };
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2" aria-live="polite">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-card p-3 text-sm shadow-lg"
            role="status"
            @click="dismiss(t.id)"
        >
            <component
                :is="icons[t.type]"
                class="mt-0.5 size-4 shrink-0"
                :class="{ 'text-success': t.type === 'success', 'text-destructive': t.type === 'error', 'text-primary': t.type === 'info' }"
            />
            <span>{{ t.message }}</span>
        </div>
    </div>
</template>
