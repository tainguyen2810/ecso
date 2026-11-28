<script setup lang="ts">
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import { cn } from '@/shared/lib/utils';

/** A carrier's real logo on a white tile with a thin border, as in the Vận chuyển mockups. */
withDefaults(defineProps<{ carrier: string | null; name?: string | null; size?: 'sm' | 'md' | 'lg' }>(), { name: null, size: 'md' });
const boxes = { sm: 'h-8 w-[72px]', md: 'h-9 w-[92px]', lg: 'h-16 w-40' };
const logos = { sm: [60, 20], md: [80, 24], lg: [136, 40] } as const;
</script>

<template>
    <span :class="cn('grid shrink-0 place-items-center rounded-[10px] border border-slate-200/80 bg-white', boxes[size])">
        <CarrierLogo :carrier="carrier" :name="name" :width="logos[size][0]" :height="logos[size][1]" />
    </span>
</template>
