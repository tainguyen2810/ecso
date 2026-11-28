<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';

/** 44px button of the page header and cards (mẫu 28/11): blue `primary`, white with a border otherwise; `sm` is 36px. */
withDefaults(defineProps<{ primary?: boolean; sm?: boolean; loading?: boolean; disabled?: boolean }>(), { primary: false, sm: false, loading: false, disabled: false });
</script>

<template>
    <button
        type="button"
        :disabled="disabled || loading"
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap font-medium transition-colors disabled:pointer-events-none disabled:opacity-60 [&_svg]:size-4 [&_svg]:shrink-0',
                sm ? 'h-9 rounded-[10px] px-3 text-[13px]' : 'h-11 rounded-[12px] px-5 text-[15px]',
                primary ? 'bg-blue-600 text-white shadow-sm hover:bg-blue-700' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
            )
        "
    >
        <Loader2 v-if="loading" class="animate-spin" /><slot />
    </button>
</template>
