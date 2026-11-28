<script setup lang="ts">
import { computed, inject } from 'vue';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { useSoft } from './soft';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-[#0077e0]',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                // Sapo: secondary buttons are blue-outlined (Thoát, Thêm sản phẩm…).
                outline: 'border border-primary bg-card text-primary hover:bg-blue-50',
                ghost: 'hover:bg-muted',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-4',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-10 px-6',
                icon: 'size-9',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

type Variants = VariantProps<typeof buttonVariants>;

const props = withDefaults(
    defineProps<{
        variant?: Variants['variant'];
        size?: Variants['size'];
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    { type: 'button' },
);

// Inside a popup (`soft-dialog`, see Dialog.vue): 44px, 12px corners, blue / white-with-border like the mockups.
const soft = inject<boolean>('soft-dialog', false) || useSoft();
const softSize: Record<string, string> = { default: 'h-11 rounded-[12px] px-5 text-[15px]', lg: 'h-11 rounded-[12px] px-6 text-[15px]', sm: 'rounded-[8px]', icon: 'rounded-[10px]' };
const softVariant: Record<string, string> = {
    default: 'bg-blue-600 text-white shadow-sm hover:bg-blue-700',
    outline: 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 hover:text-slate-900',
    ghost: 'text-slate-600 hover:bg-slate-100',
};
const classes = computed(() =>
    cn(buttonVariants({ variant: props.variant, size: props.size }), soft && softSize[props.size ?? 'default'], soft && softVariant[props.variant ?? 'default'], props.class),
);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading">
        <Loader2 v-if="loading" class="animate-spin" />
        <slot />
    </button>
</template>
